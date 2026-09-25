<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\PageUrls;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\AdminOverviewHandler;
use TowerDNS\Infrastructure\Ui\SvelteRenderer;

final class AdminOverviewHandlerTest extends TestCase
{
    public function testOnlyGrantedAdministrativeLinksAreBootstrapped(): void
    {
        $renderer = new SvelteRenderer(new ThemeManager(dirname(__DIR__, 4)));
        $handler  = new AdminOverviewHandler($renderer, new AuthorizationService(), new PageUrls());
        $user     = new User('operator', 'operator@example.test', [new Role('operator', 'Operator', [Permission::ROLE_MANAGE])]);
        $response = $handler->handle(new ServerRequest()->withAttribute(User::class, $user));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertMatchesRegularExpression('~<script id="towerdns-page" type="application/json">.*?</script>~s', $body);
        preg_match('~<script id="towerdns-page" type="application/json">(.*?)</script>~s', $body, $matches);
        $bootstrap = json_decode($matches[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('admin', $bootstrap['page']);
        self::assertSame([['key' => 'navigation.roles', 'href' => '/roles.php']], $bootstrap['props']['areas']);
    }
}
