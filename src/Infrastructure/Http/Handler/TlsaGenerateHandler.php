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

/** @psalm-api Registered in Routes. */
final readonly class TlsaGenerateHandler implements RequestHandlerInterface
{
    public function __construct(private TlsaGenerator $generator) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$request->getAttribute(User::class) instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], 401, ['Cache-Control' => 'no-store']);
        }
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = (array) ($request->getParsedBody() ?? []);
        if (!$guard instanceof CsrfGuardInterface || !is_string($body['csrf_token'] ?? null) || $body['csrf_token'] === '' || !$guard->validateToken($body['csrf_token'], 'tlsa_preview')) {
            return new JsonResponse(['error' => 'invalid_request'], 400);
        }
        $token = $guard->generateToken('tlsa_preview');
        try {
            $certificates = $body['certificates'] ?? null;
            if (!is_string($certificates)) {
                throw new \InvalidArgumentException('Invalid certificates.');
            }
            if (($body['inspect'] ?? null) === '1') {
                return new JsonResponse(['certificates' => $this->generator->inspect($certificates, is_string($body['hostname'] ?? null) && $body['hostname'] !== '' ? $body['hostname'] : null), 'csrf_token' => $token, 'verification' => 'not_verified'], 200, ['Cache-Control' => 'no-store']);
            }
            $usage    = filter_var($body['usage'] ?? null, FILTER_VALIDATE_INT);
            $selector = filter_var($body['selector'] ?? null, FILTER_VALIDATE_INT);
            $matching = filter_var($body['matching'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($usage) || !is_int($selector) || !is_int($matching) || !is_string($body['certificates'] ?? null)) {
                throw new \InvalidArgumentException('Invalid TLSA input.');
            }
            $indexes = isset($body['indexes']) && is_string($body['indexes']) ? $this->indexes($body['indexes']) : null;
            return new JsonResponse(['records' => $this->generator->generate($certificates, $usage, $selector, $matching, $indexes), 'csrf_token' => $token, 'verification' => 'not_verified'], 200, ['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'invalid_certificate_or_parameters', 'csrf_token' => $token], 422);
        }
    }
    /** @return list<int> */
    private function indexes(string $input): array
    {
        if (preg_match('/^[0-9]{1,2}(?:,[0-9]{1,2}){0,31}$/D', $input) !== 1) {
            throw new \InvalidArgumentException('Invalid certificate selection.');
        }
        return array_map(intval(...), explode(',', $input));
    }
}
