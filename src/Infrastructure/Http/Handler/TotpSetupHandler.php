<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Doctrine\DBAL\Connection;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Router\RouteResult;
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Exception\TotpCredentialLimitException;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthenticationPathPolicy;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Services\TotpService;
use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\FormInput;
use TowerDNS\Infrastructure\Http\StepUpRequestService;
use TowerDNS\Infrastructure\Persistence\PlatformDetector;

/** Manages pending TOTP enrollment and individually revocable TOTP credentials. */
final readonly class TotpSetupHandler implements RequestHandlerInterface
{
    private const string SESSION_KEY      = 'totp_setup_pending';
    private const string ISSUER           = 'TowerDNS';
    private const int PENDING_TTL_SECONDS = 300;

    /** @psalm-suppress PossiblyUnusedMethod Resolved by the route container from the handler class name. */
    public function __construct(
        private Connection $connection,
        private TemplateRendererInterface $renderer,
        private TotpSecretService $secrets,
        private TotpService $totp,
        private AuditLogService $audit,
        private TranslatorInterface $translator,
        private StepUpRequestService $stepUp,
        private WebAuthnCredentialRepositoryInterface $webAuthnCredentials,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);
        /** @var CsrfGuardInterface $guard */
        $guard   = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $session = $request->getAttribute(SessionInterface::class);
        if (!$session instanceof SessionInterface) {
            return new RedirectResponse('/login');
        }
        if ($request->getAttribute('impersonation_session') instanceof AdminImpersonationSession) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }

        if ($request->getMethod() === 'GET') {
            if (($request->getQueryParams()['setup'] ?? null) === '1') {
                return $this->beginSetup($request, $session, $user, $guard);
            }
            return $this->renderPage($user, $session, $guard, null, null);
        }

        return $this->handlePost($request, $session, $user, $guard);
    }

    private function beginSetup(ServerRequestInterface $request, SessionInterface $session, User $user, CsrfGuardInterface $guard): ResponseInterface
    {
        if ($this->secrets->count($user->id) >= $this->secrets->maxCredentialsPerUser()) {
            return $this->renderPage($user, $session, $guard, $this->translator->translate('totp.error.limit-reached'), null, 409);
        }
        if (!$this->stepUp->hasProof($request, $user->id, StepUpAction::PROFILE_TOTP_ENROLL, $user->id)) {
            return $this->stepUp->challengeAction($request, $user->id, StepUpAction::PROFILE_TOTP_ENROLL, $user->id);
        }

        $createdAt = time();
        $label     = trim((string) ($request->getQueryParams()['label'] ?? ''));
        $label     = $label !== '' ? mb_substr($label, 0, 100) : 'Authenticator';
        $secret    = $this->totp->generateSecret();
        $session->set(self::SESSION_KEY, [
            'user_id'    => $user->id,
            'secret'     => $secret,
            'label'      => $label,
            'created_at' => $createdAt,
            'expires_at' => $createdAt + self::PENDING_TTL_SECONDS,
            'purpose'    => 'totp.enrollment',
        ]);

        return $this->renderPage($user, $session, $guard, null, null);
    }

    private function handlePost(ServerRequestInterface $request, SessionInterface $session, User $user, CsrfGuardInterface $guard): ResponseInterface
    {
        $body = FormInput::fromParsedBody($request->getParsedBody());
        if (!$guard->validateToken($body['csrf_token'] ?? '')) {
            return $this->renderPage($user, $session, $guard, $this->translator->translate('auth.error.invalid-request'), null, 400);
        }

        $action = $body['action'] ?? '';
        /** @var array<string, string> $routeParams */
        $routeParams = $request->getAttribute(RouteResult::class)?->getMatchedParams() ?? [];
        if (isset($routeParams['credentialId'])) {
            return $this->delete($request, $session, $user, $guard, $routeParams['credentialId']);
        }
        if ($action === 'enable') {
            return $this->enable($request, $session, $user, $guard, trim($body['code'] ?? ''));
        }
        if ($action === 'delete') {
            return $this->delete($request, $session, $user, $guard, trim($body['credential_id'] ?? ''));
        }

        return new RedirectResponse('/profile/totp');
    }

    private function enable(ServerRequestInterface $request, SessionInterface $session, User $user, CsrfGuardInterface $guard, string $code): ResponseInterface
    {
        $pending = $this->pending($session, $user->id);
        if ($pending === null) {
            return $this->renderPage($user, $session, $guard, $this->translator->translate('totp.error.setup-expired'), null, 409);
        }
        $secret = $pending['secret'];
        if ($code === '' || $secret === '' || !$this->totp->verify($code, $secret)) {
            return $this->renderPage($user, $session, $guard, $this->translator->translate('totp.error.code-invalid'), null, 401);
        }

        $proof = $this->stepUp->consume($request, $user->id, StepUpAction::PROFILE_TOTP_ENROLL, $user->id);
        if (!$proof instanceof \TowerDNS\Application\DTO\StepUpProof) {
            return $this->stepUp->challengeAction($request, $user->id, StepUpAction::PROFILE_TOTP_ENROLL, $user->id);
        }

        try {
            $this->secrets->enable($user->id, $secret, $pending['label']);
        } catch (TotpCredentialLimitException) {
            $session->unset(self::SESSION_KEY);
            return $this->renderPage($user, $session, $guard, $this->translator->translate('totp.error.limit-reached'), null, 409);
        }
        $session->unset(self::SESSION_KEY);
        $this->audit->record($request, 'user.totp.credential.added', 'user', $user->id, $user->id, null, null, null, null, $user->id, null, null, ['step_up_method' => $proof->method]);

        return $this->renderPage($user, $session, $guard, null, $this->translator->translate('totp.success.enabled'));
    }

    private function delete(ServerRequestInterface $request, SessionInterface $session, User $user, CsrfGuardInterface $guard, string $credentialId): ResponseInterface
    {
        if ($credentialId === '') {
            return $this->renderPage($user, $session, $guard, $this->translator->translate('totp.error.credential-not-found'), null, 404);
        }
        if (!array_any($this->secrets->list($user->id), static fn(array $entry): bool => $entry['id'] === $credentialId)) {
            return $this->renderPage($user, $session, $guard, $this->translator->translate('totp.error.credential-not-found'), null, 404);
        }
        if ($this->secrets->count($user->id) === 1 && $this->webAuthnCredentials->countByUserId($user->id) === 0) {
            return $this->renderPage($user, $session, $guard, $this->translator->translate('totp.error.last-factor-recovery'), null, 409);
        }
        $stepUpTarget = hash('sha256', $credentialId);
        if (!$this->stepUp->hasProof($request, $user->id, StepUpAction::PROFILE_TOTP_DELETE, $stepUpTarget)) {
            return $this->stepUp->challengeAction($request, $user->id, StepUpAction::PROFILE_TOTP_DELETE, $stepUpTarget);
        }

        $result = $this->connection->transactional(function (Connection $connection) use ($request, $user, $credentialId, $stepUpTarget): string {
            if (!PlatformDetector::isSqlite($connection)) {
                $connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user->id]);
            }
            $count = $this->secrets->count($user->id);
            if ($count < 1 || !array_any($this->secrets->list($user->id), static fn(array $entry): bool => $entry['id'] === $credentialId)) {
                return 'missing';
            }
            $proof = $this->stepUp->consume($request, $user->id, StepUpAction::PROFILE_TOTP_DELETE, $stepUpTarget);
            if (!$proof instanceof \TowerDNS\Application\DTO\StepUpProof) {
                return 'step-up';
            }

            $proofCredentialIdHash = $proof->credentialIdHash;
            if (!is_string($proofCredentialIdHash)) {
                return 'step-up';
            }
            $webAuthnCredentials         = $this->webAuthnCredentials->findByUserId($user->id);
            $proofMatchesOwnedCredential = match ($proof->method) {
                'webauthn' => array_any(
                    $webAuthnCredentials,
                    static fn(array $entry): bool => hash_equals($proofCredentialIdHash, hash('sha256', $entry['source']->publicKeyCredentialId)),
                ),
                'totp' => array_any(
                    $this->secrets->list($user->id),
                    static fn(array $entry): bool => hash_equals($proofCredentialIdHash, hash('sha256', $entry['id'])),
                ),
                default => false,
            };
            if (!$proofMatchesOwnedCredential) {
                return 'step-up';
            }
            if (!AuthenticationPathPolicy::permitsTotpRemoval(
                $count,
                count($webAuthnCredentials),
                $proof->method,
                $proofCredentialIdHash,
                $stepUpTarget,
            )) {
                return $count === 1 ? 'last-path' : 'wrong-factor';
            }

            $this->secrets->delete($user->id, $credentialId);
            $this->audit->record($request, 'user.totp.credential.removed', 'user', $user->id, $user->id, null, null, null, null, $user->id, null, null, ['step_up_method' => $proof->method]);

            return 'deleted';
        });

        if ($result === 'missing') {
            return $this->renderPage($user, $session, $guard, $this->translator->translate('totp.error.credential-not-found'), null, 404);
        }
        if ($result === 'last-path') {
            return $this->renderPage($user, $session, $guard, $this->translator->translate('totp.error.last-factor-recovery'), null, 409);
        }
        if ($result === 'wrong-factor') {
            return $this->renderPage($user, $session, $guard, $this->translator->translate('totp.error.use-other-factor'), null, 409);
        }
        if ($result === 'step-up') {
            return $this->stepUp->challengeAction($request, $user->id, StepUpAction::PROFILE_TOTP_DELETE, $stepUpTarget);
        }

        return $this->renderPage($user, $session, $guard, null, $this->translator->translate('totp.success.disabled'));
    }

    /** @return array{user_id: string, secret: string, label: string, created_at: int, expires_at: int, purpose: string}|null */
    private function pending(SessionInterface $session, string $userId): ?array
    {
        $pending = $session->get(self::SESSION_KEY);
        $now     = time();
        if (!is_array($pending)
            || ($pending['user_id'] ?? null) !== $userId
            || !is_string($pending['secret'] ?? null)
            || !is_string($pending['label'] ?? null)
            || !is_int($pending['created_at'] ?? null)
            || !is_int($pending['expires_at'] ?? null)
            || ($pending['purpose'] ?? null) !== 'totp.enrollment'
            || $pending['created_at'] > $now
            || $pending['expires_at'] < $now
            || $pending['expires_at'] - $pending['created_at'] > self::PENDING_TTL_SECONDS) {
            $session->unset(self::SESSION_KEY);
            return null;
        }

        /** @var array{user_id: string, secret: string, label: string, created_at: int, expires_at: int, purpose: string} $pending */
        return $pending;
    }

    private function renderPage(User $user, SessionInterface $session, CsrfGuardInterface $guard, ?string $error, ?string $success, int $status = 200): HtmlResponse
    {
        $pending = $this->pending($session, $user->id);
        $secret  = $pending['secret'] ?? null;
        return new HtmlResponse($this->renderer->render('app::profile/totp', [
            'user'             => $user,
            'totpCredentials'  => $this->secrets->list($user->id),
            'totpCount'        => $this->secrets->count($user->id),
            'totpLimit'        => $this->secrets->maxCredentialsPerUser(),
            'webAuthnKeyCount' => $this->webAuthnCredentials->countByUserId($user->id),
            'provisioningUri'  => is_string($secret) ? $this->totp->getProvisioningUri($secret, $user->email, self::ISSUER) : null,
            'secret'           => $secret,
            'secretFormatted'  => is_string($secret) ? implode(' ', str_split($secret, 4)) : null,
            'pendingLabel'     => $pending['label'] ?? null,
            'error'            => $error,
            'success'          => $success,
            'csrfToken'        => $guard->generateToken(),
        ]), $status);
    }
}
