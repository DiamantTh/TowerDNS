<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Services\ActiveAccountService;
use TowerDNS\Domain\Auth\User;

final readonly class ActiveAccountHandler implements RequestHandlerInterface
{
    public function __construct(private ActiveAccountService $accounts) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        $guard   = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body    = (array) ($request->getParsedBody() ?? []);
        if (!$session instanceof SessionInterface || !$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new RedirectResponse('/accounts?error=invalid-request');
        }
        $user = $request->getAttribute(User::class);
        if (!$user instanceof User) {
            return new RedirectResponse('/login');
        }
        try {
            $account = $this->accounts->select($user, (int) ($body['account_id'] ?? 0));
            $session->set('active_account_id', $account->id);
        } catch (\DomainException) {
            $session->unset('active_account_id');
            return new RedirectResponse('/accounts?error=account-unavailable');
        }
        return new RedirectResponse('/accounts/' . $account->id);
    }
}
