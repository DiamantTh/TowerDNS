<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Laminas\I18n\Translator\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Services\SupportedLocales;
use TowerDNS\Domain\Auth\User;

final readonly class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(private Translator $translator, private string $defaultLocale = SupportedLocales::DEFAULT) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user   = $request->getAttribute(User::class);
        $locale = $user instanceof User ? $user->language : $this->defaultLocale;
        $this->translator->setLocale(SupportedLocales::normalize($locale) ?? SupportedLocales::DEFAULT);
        return $handler->handle($request);
    }
}
