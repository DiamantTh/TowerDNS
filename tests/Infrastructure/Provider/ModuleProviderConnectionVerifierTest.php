<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Provider;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\ConnectionVerifiableProviderInterface;
use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Contracts\ProviderCapabilitySet;
use TowerDNS\Application\DTO\ProviderConnectionVerification;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\ProviderDefinition;
use TowerDNS\Application\Module\ProviderModuleInterface;
use TowerDNS\Application\Module\ProviderModuleRegistry;
use TowerDNS\Domain\DNS\DNSSECProfile;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\Zone;
use TowerDNS\Infrastructure\Provider\ModuleProviderConnectionVerifier;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class ModuleProviderConnectionVerifierTest extends TestCase
{
    public function testHttpFailuresMapToStableCategoriesWithoutExposingProviderMessages(): void
    {
        foreach ([
            401 => ProviderConnectionVerification::AUTHENTICATION_FAILED,
            403 => ProviderConnectionVerification::PERMISSION_DENIED,
            429 => ProviderConnectionVerification::RATE_LIMITED,
            503 => ProviderConnectionVerification::PROVIDER_ERROR,
        ] as $status => $expected) {
            $result = $this->verifier(new ProviderRequestException('secret response must not escape', $status))->verify('test', ['token' => 'sensitive']);
            self::assertSame($expected, $result->category);
            self::assertSame($status, $result->httpStatus);
        }
    }

    public function testConnectTimeoutIsClassifiedWithoutLeakingTransportDetails(): void
    {
        $failure = new ConnectException('request headers contain credentials', new Request('GET', 'https://provider.invalid'), null, ['errno' => 28]);
        $result  = $this->verifier(new ProviderRequestException('transport failed', previous: $failure))->verify('test', ['token' => 'sensitive']);

        self::assertSame(ProviderConnectionVerification::TIMEOUT, $result->category);
        self::assertNull($result->httpStatus);
    }

    public function testTlsAndEndpointFailuresHaveDistinctCategories(): void
    {
        foreach ([
            60 => ProviderConnectionVerification::TLS_ERROR,
            6  => ProviderConnectionVerification::ENDPOINT_UNREACHABLE,
        ] as $errno => $expected) {
            $failure = new ConnectException('sensitive transport detail', new Request('GET', 'https://provider.invalid'), null, ['errno' => $errno]);
            $result  = $this->verifier(new ProviderRequestException('transport failed', previous: $failure))->verify('test', []);
            self::assertSame($expected, $result->category);
        }
    }

    public function testMalformedProviderResponseIsNotPersistedAsDiagnosticText(): void
    {
        $result = $this->verifier(new ProviderRequestException('response contained secret payload', 0))->verify('test', []);

        self::assertSame(ProviderConnectionVerification::MALFORMED_RESPONSE, $result->category);
        self::assertObjectNotHasProperty('message', $result);
    }

    private function verifier(\Throwable $failure): ModuleProviderConnectionVerifier
    {
        $module = new readonly class ($failure) implements ProviderModuleInterface {
            public function __construct(private \Throwable $failure) {}

            #[\Override]
            public function manifest(): ModuleManifest
            {
                return new ModuleManifest('towerdns.testprovider', 'Test provider', '1.0.0', ModuleType::PROVIDER);
            }

            #[\Override]
            public function providerDefinition(): ProviderDefinition
            {
                return new ProviderDefinition('test', 'Test', true, []);
            }

            #[\Override]
            public function buildProvider(array $credentials): DNSProviderInterface
            {
                return new readonly class ($this->failure) implements DNSProviderInterface, ConnectionVerifiableProviderInterface {
                    public function __construct(private \Throwable $failure) {}

                    #[\Override]
                    public function verifyConnection(): ProviderConnectionVerification
                    {
                        throw $this->failure;
                    }
                    #[\Override]
                    public function id(): string
                    {
                        return 'test';
                    }
                    #[\Override]
                    public function displayName(): string
                    {
                        return 'Test';
                    }
                    #[\Override]
                    public function capabilities(): ProviderCapabilitySet
                    {
                        return new ProviderCapabilitySet([]);
                    }
                    #[\Override]
                    public function listZones(): array
                    {
                        return [];
                    }
                    #[\Override]
                    public function createZone(string $zoneName): Zone
                    {
                        throw new \LogicException();
                    }
                    #[\Override]
                    public function deleteZone(string $zoneId): void {}

                    #[\Override]
                    public function listRecords(string $zoneId): array
                    {
                        return [];
                    }
                    #[\Override]
                    public function createRecord(Record $record): Record
                    {
                        throw new \LogicException();
                    }
                    #[\Override]
                    public function updateRecord(Record $record): Record
                    {
                        throw new \LogicException();
                    }
                    #[\Override]
                    public function deleteRecord(string $zoneId, string $recordId): void {}

                    #[\Override]
                    public function getDnssecProfile(string $zoneId): DNSSECProfile
                    {
                        throw new \LogicException();
                    }
                    #[\Override]
                    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DNSSECProfile
                    {
                        throw new \LogicException();
                    }
                };
            }
        };

        return new ModuleProviderConnectionVerifier(new ProviderModuleRegistry([$module]));
    }
}
