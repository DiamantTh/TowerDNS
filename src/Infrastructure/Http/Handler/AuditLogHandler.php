<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\FormInput;

/** @psalm-api Registered by the application routes. */
final readonly class AuditLogHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private TranslatorInterface $translator,
        private PermissionService $permissions,
        private AuditLogRepositoryInterface $audit,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user    = $request->getAttribute(User::class);
        $account = filter_var($request->getAttribute('account'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $zone    = $request->getAttribute('zone');
        if (!$user instanceof User || $account === false || !($zone === null
            ? $this->permissions->authorizeAccount($user, Permission::AUDIT_READ, $account)
            : is_string($zone) && $this->permissions->authorizeZone($user, Permission::AUDIT_READ, $account, $zone))) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }

        $query   = FormInput::fromParsedBody($request->getQueryParams());
        $filters = [];
        foreach (['action' => '/\A[\w.-]{1,128}\z/', 'target' => '/\A[\w.-]{1,64}\z/', 'provider' => '/\A[1-9][0-9]{0,9}\z/', 'since' => '/\A\d{4}-\d{2}-\d{2}\z/'] as $key => $pattern) {
            $value = $query[$key] ?? '';
            if ($value !== '') {
                if (preg_match($pattern, $value) !== 1 || ($key === 'since' && !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)))
                                                       || ($key === 'provider' && (int) $value > 2147483647)) {
                    return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
                }
                $filters[$key] = $value;
            }
        }
        $zoneId = is_string($zone) ? (string) (int) $zone : null;
        $page   = max(1, min(10000, (int) ($query['page'] ?? '1')));
        $rows   = $this->audit->pageForAccount($account, $zoneId, $filters, ($page - 1) * 50);
        $guard  = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        return new HtmlResponse($this->renderer->render('app::audit/index', [
            'user'      => $user,
            'csrfToken' => $guard instanceof CsrfGuardInterface ? $guard->generateToken() : '',
            'accountId' => $account,
            'zoneId'    => $zoneId,
            'events'    => array_slice($rows, 0, 50),
            'filters'   => $filters,
            'auditPage' => $page,
            'hasMore'   => $page < 10000 && count($rows) > 50,
        ]), 200, ['Cache-Control' => 'private, no-store']);
    }
}
