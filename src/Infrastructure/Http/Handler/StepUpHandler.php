<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\DTO\StepUpIntent;
use TowerDNS\Application\DTO\StepUpProof;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\StepUpProofService;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\RateLimit\RateLimiter;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Handles the authenticated, action-bound second-factor challenge used for
 * high-impact IAM operations and the start of an administrator switch.
 * @psalm-api Constructed through runtime dependency injection or command/handler registration.
 */
final readonly class StepUpHandler implements RequestHandlerInterface
{
    private const int RATE_LIMIT       = 6;
    private const int RATE_WINDOW_SECS = 300;

    public function __construct(
        private TemplateRendererInterface              $renderer,
        private TotpSecretService                      $totpSecrets,
        private WebAuthnService                        $webAuthn,
        private WebAuthnCredentialRepositoryInterface $credentials,
        private SessionSecurity                       $sessionSecurity,
        private StepUpProofService                    $proofs,
        private AuditLogService                       $audit,
        private TranslatorInterface                   $translator,
        private CacheInterface                        $cache,
        private UserRepositoryInterface               $users,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        $user    = $request->getAttribute(User::class);
        if (!$session instanceof SessionInterface || !$user instanceof User) {
            return new RedirectResponse('/login');
        }

        $switch   = $request->getAttribute('impersonation_session');
        $switchId = $switch instanceof AdminImpersonationSession ? $switch->id : null;
        $path     = $request->getUri()->getPath();

        if ($request->getMethod() === 'GET' && $path === '/security/step-up') {
            return $this->show($request, $session, $user, $switchId);
        }

        if ($request->getMethod() === 'POST' && $path === '/security/step-up/totp') {
            return $this->verifyTotp($request, $session, $user, $switchId);
        }

        if ($request->getMethod() === 'POST' && $path === '/security/step-up/password') {
            return $this->verifyPassword($request, $session, $user, $switchId);
        }

        if ($request->getMethod() === 'POST' && $path === '/security/step-up/webauthn/begin') {
            return $this->beginWebAuthn($request, $session, $user, $switchId);
        }

        if ($request->getMethod() === 'POST' && $path === '/security/step-up/webauthn/finish') {
            return $this->finishWebAuthn($request, $session, $user, $switchId);
        }

        return new JsonResponse(['error' => $this->translator->translate('http.error.not-found')], 404);
    }

    private function show(ServerRequestInterface $request, SessionInterface $session, User $user, ?string $switchId): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard   = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $pending = $this->sessionSecurity->pendingStepUp($session, $user->id, $switchId);
        if (!$pending instanceof StepUpIntent) {
            $proof = $this->sessionSecurity->availableStepUpProof($session, $this->proofs);
            if ($proof instanceof StepUpProof && $proof->actorUserId === $user->id && $proof->impersonationSessionId === $switchId) {
                return $this->render($guard, [
                    'completed'         => true,
                    'returnUrl'         => $this->returnPath($proof),
                    'totpAvailable'     => false,
                    'passkeyAvailable'  => false,
                    'passwordAvailable' => false,
                    'error'             => null,
                ]);
            }

            $this->sessionSecurity->clearStepUp($session);
            return new RedirectResponse('/');
        }

        $registeredWebAuthn = $this->credentials->findByUserId($user->id);
        $registeredTotp     = $this->totpSecrets->list($user->id);
        $hasPasskey         = $registeredWebAuthn !== [];
        $hasTotp            = $registeredTotp     !== [];
        $factorHint         = null;
        $recoveryRequired   = false;

        if ($pending->action === StepUpAction::PROFILE_WEBAUTHN_DELETE) {
            $hasPasskey = count($registeredWebAuthn) >= 2;
            $hasTotp    = false;
            if (count($registeredWebAuthn) === 2) {
                $factorHint = 'security.step-up.webauthn-delete-other-key';
            }
        } elseif ($pending->action === StepUpAction::IAM_USER_WEBAUTHN_REVOKE) {
            $hasTotp    = false;
            $factorHint = 'security.step-up.admin-webauthn-only';
        } elseif ($pending->action === StepUpAction::PROFILE_TOTP_DELETE) {
            if (count($registeredTotp) === 1) {
                $hasTotp = false;
                if (!$hasPasskey) {
                    $recoveryRequired = true;
                } else {
                    $factorHint = 'security.step-up.totp-delete-fido2-required';
                }
            } elseif (count($registeredTotp) === 2) {
                $factorHint = 'security.step-up.totp-delete-other-factor';
            }
        }
        $error             = $request->getQueryParams()['error'] ?? null;
        $passwordAvailable = !in_array($pending->action, [StepUpAction::IAM_USER_WEBAUTHN_REVOKE, StepUpAction::PROFILE_WEBAUTHN_DELETE, StepUpAction::PROFILE_TOTP_DELETE], true)
            && !$hasPasskey
            && !$hasTotp
            && $this->users->fetchPasswordHash($user->email) !== null;

        return $this->render($guard, [
            'completed'         => false,
            'returnUrl'         => null,
            'totpAvailable'     => $hasTotp,
            'passkeyAvailable'  => $hasPasskey,
            'passwordAvailable' => $passwordAvailable,
            'factorHint'        => $factorHint,
            'recoveryRequired'  => $recoveryRequired,
            'error'             => is_string($error) ? $error : null,
        ]);
    }

    private function verifyPassword(ServerRequestInterface $request, SessionInterface $session, User $user, ?string $switchId): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = \TowerDNS\Infrastructure\Http\FormInput::fromParsedBody($request->getParsedBody());
        if (!$guard->validateToken($body['csrf_token'] ?? '')) {
            return $this->render($guard, ['error' => $this->translator->translate('http.error.invalid-request')], 400);
        }

        $intent = $this->sessionSecurity->pendingStepUp($session, $user->id, $switchId);
        $hash   = $this->users->fetchPasswordHash($user->email);
        if (!$intent instanceof StepUpIntent
            || in_array($intent->action, [StepUpAction::IAM_USER_WEBAUTHN_REVOKE, StepUpAction::PROFILE_WEBAUTHN_DELETE, StepUpAction::PROFILE_TOTP_DELETE], true)
            || $hash === null
            || $this->credentials->findByUserId($user->id) !== []
            || $this->totpSecrets->isEnabled($user->id)) {
            return $this->render($guard, ['error' => $this->translator->translate('security.step-up.unavailable')], 403);
        }
        if (!$this->hitRateLimit('password', $user->id)) {
            return $this->render($guard, ['error' => $this->translator->translate('auth.error.rate-limited')], 429);
        }

        $password = $body['password'] ?? '';
        if ($password === '' || !password_verify($password, $hash)) {
            $this->recordAttempt($request, $user, $switchId, $intent, 'password', false);
            return $this->render($guard, ['error' => $this->translator->translate('auth.error.invalid-credentials')], 401);
        }

        $proof = $this->sessionSecurity->completeStepUp($session, $user->id, $switchId, 'password', $this->proofs);
        if (!$proof instanceof StepUpProof) {
            return $this->render($guard, ['error' => $this->translator->translate('security.step-up.expired')], 409);
        }
        $this->recordAttempt($request, $user, $switchId, $intent, 'password', true);
        return new RedirectResponse('/security/step-up?verified=1');
    }

    private function verifyTotp(ServerRequestInterface $request, SessionInterface $session, User $user, ?string $switchId): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        if (!$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return $this->render($guard, ['error' => $this->translator->translate('http.error.invalid-request')], 400);
        }

        $intent = $this->sessionSecurity->pendingStepUp($session, $user->id, $switchId);
        if (!$intent instanceof StepUpIntent
            || in_array($intent->action, [StepUpAction::IAM_USER_WEBAUTHN_REVOKE, StepUpAction::PROFILE_WEBAUTHN_DELETE], true)
            || !$this->totpSecrets->isEnabled($user->id)) {
            return $this->render($guard, ['error' => $this->translator->translate('security.step-up.unavailable')], 403);
        }

        if (!$this->hitRateLimit('totp', $user->id)) {
            return $this->render($guard, ['error' => $this->translator->translate('auth.error.rate-limited')], 429);
        }

        $code                 = trim((string) ($body['code'] ?? ''));
        $totpCredentials      = $this->totpSecrets->list($user->id);
        $excludedCredentialId = null;
        if ($intent->action === StepUpAction::PROFILE_TOTP_DELETE) {
            if (count($totpCredentials) < 2) {
                return $this->render($guard, ['error' => $this->translator->translate('security.step-up.unavailable')], 403);
            }
            if (count($totpCredentials) === 2) {
                foreach ($totpCredentials as $credential) {
                    if (hash_equals($intent->targetId, hash('sha256', $credential['id']))) {
                        $excludedCredentialId = $credential['id'];
                        break;
                    }
                }
                if ($excludedCredentialId === null) {
                    return $this->render($guard, ['error' => $this->translator->translate('security.step-up.unavailable')], 403);
                }
            }
        }

        $verifiedCredentialId = $code === '' ? null : $this->totpSecrets->verifyCredential($user->id, $code, $excludedCredentialId);
        if ($verifiedCredentialId === null) {
            $this->recordAttempt($request, $user, $switchId, $intent, 'totp', false);
            return $this->render($guard, ['error' => $this->translator->translate('totp.error.code-invalid')], 401);
        }

        $proof = $this->sessionSecurity->completeStepUp(
            $session,
            $user->id,
            $switchId,
            'totp',
            $this->proofs,
            hash('sha256', $verifiedCredentialId),
        );
        if (!$proof instanceof StepUpProof) {
            return $this->render($guard, ['error' => $this->translator->translate('security.step-up.expired')], 409);
        }

        $this->recordAttempt($request, $user, $switchId, $intent, 'totp', true);
        return new RedirectResponse('/security/step-up?verified=1');
    }

    private function beginWebAuthn(ServerRequestInterface $request, SessionInterface $session, User $user, ?string $switchId): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        if (!$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new JsonResponse(['error' => $this->translator->translate('http.error.invalid-request')], 400);
        }

        $intent = $this->sessionSecurity->pendingStepUp($session, $user->id, $switchId);
        if (!$intent instanceof StepUpIntent) {
            return new JsonResponse(['error' => $this->translator->translate('security.step-up.expired')], 409);
        }

        $credentials = $this->credentials->findByUserId($user->id);
        if ($intent->action === StepUpAction::PROFILE_WEBAUTHN_DELETE) {
            if (count($credentials) < 2) {
                return new JsonResponse(['error' => $this->translator->translate('security.step-up.unavailable')], 403);
            }
            if (count($credentials) === 2) {
                $credentials = array_values(array_filter(
                    $credentials,
                    static fn(array $credential): bool => !hash_equals($intent->targetId, hash('sha256', $credential['source']->publicKeyCredentialId)),
                ));
            }
        }
        if ($credentials === []) {
            return new JsonResponse(['error' => $this->translator->translate('security.step-up.unavailable')], 403);
        }

        $ids     = array_map(static fn(array $credential): string => $credential['source']->publicKeyCredentialId, $credentials);
        $options = $this->webAuthn->createAuthenticationOptions(
            $ids,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        );
        $serialized = $this->webAuthn->serializeRequestOptions($options);
        $session->set('step_up_webauthn_options', $serialized);

        return new JsonResponse(json_decode($serialized, true, flags: JSON_THROW_ON_ERROR));
    }

    private function finishWebAuthn(ServerRequestInterface $request, SessionInterface $session, User $user, ?string $switchId): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = (string) $request->getBody();
        /** @var array<string, mixed>|null $parsed */
        $parsed = json_decode($body, true);
        if (!is_array($parsed) || !$guard->validateToken((string) ($parsed['csrf_token'] ?? ''))) {
            return new JsonResponse(['error' => $this->translator->translate('http.error.invalid-request')], 400);
        }

        $intent      = $this->sessionSecurity->pendingStepUp($session, $user->id, $switchId);
        $optionsJson = $session->get('step_up_webauthn_options');
        // Consume the browser challenge before parsing an assertion so it
        // cannot be replayed after an invalid response.
        $session->unset('step_up_webauthn_options');
        if (!$intent instanceof StepUpIntent || !is_string($optionsJson) || $optionsJson === '') {
            return new JsonResponse(['error' => $this->translator->translate('security.step-up.expired')], 409);
        }

        if (!$this->hitRateLimit('webauthn', $user->id)) {
            return new JsonResponse(['error' => $this->translator->translate('auth.error.rate-limited')], 429);
        }

        $rawId = $parsed['rawId'] ?? $parsed['id'] ?? null;
        if (!is_string($rawId) || $rawId === '') {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.invalid-response')], 422);
        }
        $credentialId = base64_decode(strtr($rawId, '-_', '+/'), true);
        if (!is_string($credentialId)) {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.invalid-response')], 422);
        }
        $source = $this->credentials->findByCredentialIdForUser($credentialId, $user->id);
        if (!$source instanceof \Webauthn\CredentialRecord) {
            $this->recordAttempt($request, $user, $switchId, $intent, 'webauthn', false);
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.authentication-failed')], 422);
        }

        if ($intent->action === StepUpAction::PROFILE_WEBAUTHN_DELETE) {
            $ownedCredentials = $this->credentials->findByUserId($user->id);
            if (count($ownedCredentials) < 2
                || (count($ownedCredentials) === 2 && hash_equals($intent->targetId, hash('sha256', $credentialId)))) {
                $this->recordAttempt($request, $user, $switchId, $intent, 'webauthn', false);
                return new JsonResponse(['error' => $this->translator->translate('security.step-up.webauthn-delete-other-key')], 403);
            }
        }

        try {
            $options = $this->webAuthn->deserializeRequestOptions($optionsJson);
            $updated = $this->webAuthn->parseAndValidateAuthentication($body, $source, $options, $user->id);
        } catch (AuthenticatorResponseVerificationException|\InvalidArgumentException) {
            $this->recordAttempt($request, $user, $switchId, $intent, 'webauthn', false);
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.authentication-failed')], 422);
        }

        $this->credentials->updateAfterAuthentication($updated);
        $proof = $this->sessionSecurity->completeStepUp(
            $session,
            $user->id,
            $switchId,
            'webauthn',
            $this->proofs,
            hash('sha256', $credentialId),
        );
        if (!$proof instanceof StepUpProof) {
            return new JsonResponse(['error' => $this->translator->translate('security.step-up.expired')], 409);
        }

        $this->recordAttempt($request, $user, $switchId, $intent, 'webauthn', true);
        return new JsonResponse(['ok' => true, 'redirect' => '/security/step-up?verified=1']);
    }

    /** @param array<non-empty-string, mixed> $overrides */
    private function render(CsrfGuardInterface $guard, array $overrides = [], int $status = 200): HtmlResponse
    {
        return new HtmlResponse($this->renderer->render('app::security/step_up', [
            'csrfToken'         => $guard->generateToken(),
            'completed'         => false,
            'returnUrl'         => null,
            'totpAvailable'     => false,
            'passkeyAvailable'  => false,
            'passwordAvailable' => false,
            'factorHint'        => null,
            'recoveryRequired'  => false,
            'error'             => null,
            ...$overrides,
        ]), $status);
    }

    private function hitRateLimit(string $factor, string $userId): bool
    {
        try {
            new RateLimiter($this->cache, 'step_up_' . $factor . '_' . hash('sha256', $userId), self::RATE_LIMIT, self::RATE_WINDOW_SECS, 'Step-up')->hit();
            return true;
        } catch (RateLimitExceededException) {
            return false;
        }
    }

    private function recordAttempt(ServerRequestInterface $request, User $user, ?string $switchId, StepUpIntent $intent, string $method, bool $succeeded): void
    {
        $original = $request->getAttribute('actor_user');
        $this->audit->recordWithContext(
            AuditLogService::fromHttpRequest($request, $original instanceof User ? $original->id : $user->id, $user->id, impersonationSessionId: $switchId),
            $succeeded ? 'security.step_up.succeeded' : 'security.step_up.failed',
            'action',
            $intent->action,
            null,
            null,
            ['method' => $method, 'target_id' => $intent->targetId],
        );
    }

    private function returnPath(StepUpProof $proof): string
    {
        return match ($proof->action) {
            StepUpAction::IAM_USER_ROLES, StepUpAction::IAM_USER_STATUS, StepUpAction::IAM_USER_PASSWORD => '/users/' . rawurlencode($proof->targetId),
            StepUpAction::IAM_USER_DELETE, StepUpAction::IAM_USER_WEBAUTHN_REVOKE                        => '/users',
            StepUpAction::IAM_ROLE_CREATE, StepUpAction::IAM_ROLE_SAVE, StepUpAction::IAM_ROLE_DELETE    => '/roles',
            StepUpAction::ADMIN_SWITCH                                                                   => '/admin/switch',
            StepUpAction::PROFILE_WEBAUTHN_ENROLL, StepUpAction::PROFILE_WEBAUTHN_DELETE                 => '/profile/webauthn',
            StepUpAction::PROFILE_TOTP_ENROLL, StepUpAction::PROFILE_TOTP_DELETE                         => '/profile/totp',
            StepUpAction::PROFILE_PASSWORD_CHANGE, StepUpAction::PROFILE_PASSWORD_DISABLE                => '/profile/password',
            default                                                                                      => '/',
        };
    }
}
