<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Services\ProfileService;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Persistence\DbalUserRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

final class ProfileServiceTest extends TestCase
{
    public function testLocaleDisplayNameAndThemeRoundTripWithoutExposingSecrets(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SchemaManager($connection)->createTablesIfNotExist();
        $users = new DbalUserRepository($connection, new SystemClock());
        $id    = '7f4e986d-0000-4000-8000-000000000001';
        $users->create($id, 'person@example.test', 'secret-hash');
        $user = $users->findById($id);
        self::assertNotNull($user);
        self::assertSame('en-GB', $user->locale);

        $profile = new ProfileService($users, new ThemeManager(dirname(__DIR__, 3)));
        $profile->update($user, ['display_name' => '  Example Person  ', 'theme' => 'default', 'language' => 'de-DE', 'locale' => 'de-DE', 'timezone' => 'Europe/Berlin', 'first_name' => 'Example', 'alternate_email' => 'alt@example.test', 'country' => 'de']);
        $updated = $users->findById($id);
        self::assertNotNull($updated);
        self::assertSame('Example Person', $updated->displayName);
        self::assertSame('default', $updated->theme);
        self::assertSame('de-DE', $updated->locale);
        self::assertSame('de-DE', $updated->language);
        self::assertSame('Europe/Berlin', $updated->timezone);
        self::assertSame('Example', $updated->firstName);
        self::assertSame('alt@example.test', $updated->alternateEmail);
        self::assertSame('DE', $updated->country);
        self::assertSame('secret-hash', $users->fetchPasswordHash('person@example.test'));

        foreach (['fr-FR', 'de-DE<script>', ''] as $invalid) {
            try {
                $profile->update($updated, ['display_name' => 'Other', 'theme' => 'system', 'language' => 'de-DE', 'locale' => $invalid, 'timezone' => 'UTC']);
                self::fail('Unsupported locale accepted: ' . $invalid);
            } catch (\InvalidArgumentException) {
                $unchanged = $users->findById($id);
                self::assertNotNull($unchanged);
                self::assertSame('de-DE', $unchanged->locale);
            }
        }
        foreach ([['Invalid theme', 'missing', 'de-DE'], ['Too long', 'system', 'en-GB']] as [$case, $theme, $locale]) {
            $name = $case === 'Too long' ? str_repeat('X', 65) : 'Valid';
            try {
                $profile->update($updated, ['display_name' => $name, 'theme' => $theme, 'language' => 'en-GB', 'locale' => $locale, 'timezone' => 'UTC']);
                self::fail($case . ' was accepted.');
            } catch (\InvalidArgumentException) {
                $unchanged = $users->findById($id);
                self::assertNotNull($unchanged);
                self::assertSame('de-DE', $unchanged->locale);
                self::assertSame('Example Person', $unchanged->displayName);
            }
        }
    }

    public function testRejectsInvalidTimezoneAndAlternateEmail(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SchemaManager($connection)->createTablesIfNotExist();
        $users = new DbalUserRepository($connection, new SystemClock());
        $users->create('7f4e986d-0000-4000-8000-000000000003', 'person@example.test', 'hash');
        $user = $users->findByEmail('person@example.test');
        self::assertNotNull($user);
        $service = new ProfileService($users, new ThemeManager(dirname(__DIR__, 3)));
        $this->expectException(\InvalidArgumentException::class);
        $service->update($user, ['theme' => 'system', 'language' => 'en-GB', 'locale' => 'en-GB', 'timezone' => 'not/a-zone', 'alternate_email' => 'invalid']);
    }

    public function testLegacyShortLocaleFallsBackToCanonicalCatalogue(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SchemaManager($connection)->createTablesIfNotExist();
        $users = new DbalUserRepository($connection, new SystemClock());
        $id    = '7f4e986d-0000-4000-8000-000000000002';
        $users->create($id, 'legacy@example.test', 'hash');
        $connection->update('users', ['locale' => 'de'], ['id' => $id]);
        $german = $users->findById($id);
        self::assertNotNull($german);
        self::assertSame('de-DE', $german->locale);
        $connection->update('users', ['locale' => 'unsupported'], ['id' => $id]);
        $fallback = $users->findById($id);
        self::assertNotNull($fallback);
        self::assertSame('en-GB', $fallback->locale);
    }

    public function testAdministrativeLookupIncludesInactiveUsersAndSupportsSearch(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SchemaManager($connection)->createTablesIfNotExist();
        $users = new DbalUserRepository($connection, new SystemClock());
        $users->create('active-user', 'active@example.test', 'hash');
        $users->create('inactive-user', 'inactive@example.test', 'hash');
        $users->setActive('inactive-user', false);

        self::assertNull($users->findById('inactive-user'));
        $inactive = $users->findByIdForAdministration('inactive-user');
        self::assertNotNull($inactive);
        self::assertFalse($inactive->active);
        self::assertCount(1, $users->findAll('inactive@', false));
        self::assertCount(2, $users->findAll(null, null));
    }
}
