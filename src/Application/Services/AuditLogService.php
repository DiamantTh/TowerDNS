<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Psr\Http\Message\ServerRequestInterface;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Domain\Account\AuditLogEntry;

/**
 * Write-helper for audit log entries.
 *
 * All mutations that touch DNS data, provider accounts, account memberships,
 * or admin actions MUST be recorded via this service.
 * NEVER include credentials or secrets in any parameter passed here.
 */
final readonly class AuditLogService
{
    public function __construct(private AuditLogRepositoryInterface $repository) {}

    /**
     * Core write method. All other methods delegate here.
     *
     * @param array<string, mixed>|null $before  State before mutation (no secrets)
     * @param array<string, mixed>|null $after   State after mutation (no secrets)
     * @param array<string, mixed>|null $metadata Additional context (no secrets)
     */
    public function record(
        ServerRequestInterface $request,
        string                 $action,
        string                 $targetType,
        ?string                $targetId = null,
        ?string                $actorUserId = null,
        ?int                   $accountId = null,
        ?string                $zoneId = null,
        ?int                   $providerAccountId = null,
        ?string                $impersonationSessionId = null,
        ?string                $effectiveUserId = null,
        ?array                 $before = null,
        ?array                 $after = null,
        ?array                 $metadata = null,
    ): void {
        $this->recordWithContext(
            self::fromHttpRequest(
                $request,
                $actorUserId,
                $effectiveUserId,
                $accountId,
                $zoneId,
                $providerAccountId,
                $impersonationSessionId,
            ),
            $action,
            $targetType,
            $targetId,
            $before,
            $after,
            $metadata,
        );
    }

    /**
     * Transport-neutral audit entry point for application services, CLI, and
     * future API adapters. Metadata must already be scrubbed of secrets.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $metadata
     */
    public function recordWithContext(
        AuditContext $context,
        string $action,
        string $targetType,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
    ): void {
        $entry = new AuditLogEntry(
            actorUserId: $context->actorUserId,
            action: $action,
            targetType: $targetType,
            targetId: $targetId,
            effectiveUserId: $context->effectiveUserId,
            accountId: $context->accountId,
            zoneId: $context->zoneId,
            providerAccountId: $context->providerAccountId,
            impersonationSessionId: $context->impersonationSessionId,
            beforeJson: $before,
            afterJson: $after,
            metadataJson: $metadata,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
        );
        $this->repository->append($entry, new \DateTimeImmutable()->format('Y-m-d H:i:s'));
    }

    // ── Convenience wrappers ──────────────────────────────────────────────────

    public function recordLogin(ServerRequestInterface $request, string $userId): void
    {
        $this->record($request, 'user.login', 'user', $userId, $userId);
    }

    public function recordLoginFailed(ServerRequestInterface $request, string $email): void
    {
        $this->record($request, 'user.login.failed', 'email', null, null, null, null, null, null, null, null, null, ['email' => $email]);
    }

    public function recordPasswordReset(ServerRequestInterface $request, string $userId): void
    {
        $this->record($request, 'user.password.reset', 'user', $userId, $userId, null, null, null, null, null, null, null, ['method' => 'email_link']);
    }

    public function recordPasswordSetByAdministrator(
        ServerRequestInterface $request,
        string $actorUserId,
        string $targetUserId,
        int $revokedApiKeyCount,
    ): void {
        $this->record(
            $request,
            'user.password.reset',
            'user',
            $targetUserId,
            $actorUserId,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            ['method' => 'admin_set', 'revoked_api_keys' => $revokedApiKeyCount],
        );
    }

    public function recordZoneCreate(ServerRequestInterface $request, string $actorId, ?int $accountId, string $zoneId, string $zoneName): void
    {
        $this->record($request, 'zone.create', 'zone', $zoneId, $actorId, $accountId, $zoneId, null, null, null, null, ['name' => $zoneName]);
    }

    public function recordZoneDelete(ServerRequestInterface $request, string $actorId, ?int $accountId, string $zoneId, string $zoneName): void
    {
        $this->record($request, 'zone.delete', 'zone', $zoneId, $actorId, $accountId, $zoneId, null, null, null, ['name' => $zoneName]);
    }

    public function recordRecordCreate(ServerRequestInterface $request, string $actorId, ?int $accountId, string $zoneId, string $recordName, string $type): void
    {
        $this->record($request, 'zone.record.create', 'record', null, $actorId, $accountId, $zoneId, null, null, null, null, null, ['record' => $recordName, 'type' => $type]);
    }

    public function recordRecordUpdate(ServerRequestInterface $request, string $actorId, ?int $accountId, string $zoneId, string $recordName, string $type): void
    {
        $this->record($request, 'zone.record.update', 'record', null, $actorId, $accountId, $zoneId, null, null, null, null, null, ['record' => $recordName, 'type' => $type]);
    }

    public function recordRecordDelete(ServerRequestInterface $request, string $actorId, ?int $accountId, string $zoneId, string $recordName, string $type): void
    {
        $this->record($request, 'zone.record.delete', 'record', null, $actorId, $accountId, $zoneId, null, null, null, null, null, ['record' => $recordName, 'type' => $type]);
    }

    public function recordZoneMemberGranted(ServerRequestInterface $request, string $actorId, ?int $accountId, int $managedZoneId, string $targetUserId, string $role): void
    {
        $this->record($request, 'zone.member.grant', 'user', $targetUserId, $actorId, $accountId, (string) $managedZoneId, null, null, null, null, null, ['managed_zone_id' => $managedZoneId, 'role' => $role]);
    }

    public function recordZoneMemberRevoked(ServerRequestInterface $request, string $actorId, ?int $accountId, int $managedZoneId, string $targetUserId): void
    {
        $this->record($request, 'zone.member.revoke', 'user', $targetUserId, $actorId, $accountId, (string) $managedZoneId, null, null, null, null, null, ['managed_zone_id' => $managedZoneId]);
    }

    public function recordProviderAccountCreated(ServerRequestInterface $request, string $actorId, int $accountId, int $providerAccountId, string $name, string $providerType): void
    {
        $this->record($request, 'provideraccount.create', 'provider_account', (string) $providerAccountId, $actorId, $accountId, null, $providerAccountId, null, null, null, ['name' => $name, 'type' => $providerType]);
    }

    public function recordProviderAccountCredentialsReplaced(ServerRequestInterface $request, string $actorId, int $accountId, int $providerAccountId): void
    {
        $this->record($request, 'provideraccount.credentials.replaced', 'provider_account', (string) $providerAccountId, $actorId, $accountId, null, $providerAccountId);
    }

    public function recordSystemProviderConfigurationUpdated(ServerRequestInterface $request, string $actorId, string $providerType): void
    {
        $this->record($request, 'system.provider.configuration.update', 'provider', $providerType, $actorId, null, null, null, null, null, null, null, ['provider_type' => $providerType]);
    }

    public function recordProviderAccountDeactivated(ServerRequestInterface $request, string $actorId, int $accountId, int $providerAccountId): void
    {
        $this->record($request, 'provideraccount.deactivate', 'provider_account', (string) $providerAccountId, $actorId, $accountId, null, $providerAccountId);
    }

    public function recordAdminSwitchStart(ServerRequestInterface $request, string $actorId, string $sessionId, ?string $effectiveUserId, ?int $effectiveAccountId, string $reason): void
    {
        $this->record($request, 'admin.switch.start', 'impersonation_session', $sessionId, $actorId, $effectiveAccountId, null, null, $sessionId, $effectiveUserId, null, null, ['reason' => $reason]);
    }

    public function recordAdminSwitchEnd(ServerRequestInterface $request, string $actorId, string $sessionId): void
    {
        $this->record($request, 'admin.switch.end', 'impersonation_session', $sessionId, $actorId, null, null, null, $sessionId);
    }

    public function recordMemberInvited(ServerRequestInterface $request, string $actorId, int $accountId, string $targetUserId, string $role): void
    {
        $this->record($request, 'account.member.invite', 'user', $targetUserId, $actorId, $accountId, null, null, null, null, null, null, ['role' => $role]);
    }

    public function recordMemberRemoved(ServerRequestInterface $request, string $actorId, int $accountId, string $targetUserId): void
    {
        $this->record($request, 'account.member.remove', 'user', $targetUserId, $actorId, $accountId);
    }

    public function recordTotpEnabled(ServerRequestInterface $request, string $userId): void
    {
        $this->record($request, 'user.mfa.totp.enable', 'user', $userId, $userId);
    }

    public function recordTotpDisabled(ServerRequestInterface $request, string $userId): void
    {
        $this->record($request, 'user.mfa.totp.disable', 'user', $userId, $userId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function fromHttpRequest(
        ServerRequestInterface $request,
        ?string $actorUserId,
        ?string $effectiveUserId = null,
        ?int $accountId = null,
        ?string $zoneId = null,
        ?int $providerAccountId = null,
        ?string $impersonationSessionId = null,
    ): AuditContext
    {
        // Trust X-Forwarded-For only if you control the proxy tier.
        // For now: use REMOTE_ADDR only.
        $params = $request->getServerParams();
        $ip     = $params['REMOTE_ADDR'] ?? null;

        return new AuditContext(
            $actorUserId,
            $effectiveUserId,
            $accountId,
            $zoneId,
            $providerAccountId,
            $impersonationSessionId,
            is_string($ip) ? $ip : null,
            $request->getHeaderLine('User-Agent') ?: null,
        );
    }
}
