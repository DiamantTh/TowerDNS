<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\DNS\TlsaGenerator;
use TowerDNS\Domain\Auth\User;

/** Authenticated, CSRF-protected certificate-to-TLSA calculation; no network or shell access. */
final readonly class TlsaGenerateHandler implements RequestHandlerInterface
{
    public function __construct(private TlsaGenerator $generator) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$request->getAttribute(User::class) instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = (array) ($request->getParsedBody() ?? []);
        if (!$guard instanceof CsrfGuardInterface || !$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new JsonResponse(['error' => 'invalid_request'], 400);
        }
        try {
            $usage    = filter_var($body['usage'] ?? null, FILTER_VALIDATE_INT);
            $selector = filter_var($body['selector'] ?? null, FILTER_VALIDATE_INT);
            $matching = filter_var($body['matching'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($usage) || !is_int($selector) || !is_int($matching) || !is_string($body['certificates'] ?? null)) {
                throw new \InvalidArgumentException('Invalid TLSA input.');
            }
            return new JsonResponse(['records' => $this->generator->generate($body['certificates'], $usage, $selector, $matching)]);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'invalid_certificate_or_parameters'], 422);
        }
    }
}
