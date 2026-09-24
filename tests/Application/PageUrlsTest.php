<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\PageUrls;

final class PageUrlsTest extends TestCase
{
    public function testPhpPagesEncodeIdsAndPreserveFlashQueries(): void
    {
        $urls = new PageUrls();
        self::assertSame('/roles.php?id=role%2Fwith%20space', $urls->page('role', ['id' => 'role/with space']));
        self::assertSame('/records.php?account=107&zone=21', $urls->page('records', ['account' => 107, 'zone' => 21]));
        self::assertSame('/zones.php?account=107&zone=21&action=delete', $urls->page('zoneDelete', ['account' => 107, 'zone' => 21]));
        self::assertSame('/records.php?account=107&zone=21&action=replace', $urls->page('rrsetReplace', ['account' => 107, 'zone' => 21]));
        self::assertSame('/records.php?account=107&zone=21&action=delete-rrset&owner=long%2Ftxt&type=TXT', $urls->page('rrsetDelete', ['account' => 107, 'zone' => 21, 'owner' => 'long/txt', 'type' => 'TXT']));
        self::assertSame('/roles.php?id=role-42&success=ok', $urls->redirect('/roles/role-42?success=ok'));
        self::assertSame('/records.php?account=107&zone=21&error=bad', $urls->redirect('/accounts/107/zones/21?error=bad'));
        self::assertSame('/records.php?account=107&zone=21&record=rec-4', $urls->redirect('/accounts/107/zones/21/records/rec-4/edit'));
        self::assertSame('https://example.test/login', $urls->redirect('https://example.test/login'));
        self::assertSame('//example.test/login', $urls->redirect('//example.test/login'));
        self::assertSame('/accounts.php?id=107&view=providers', $urls->redirect('/accounts/107/providers'));
    }

    public function testPathStyleKeepsExistingRoutes(): void
    {
        $urls = new PageUrls('path');
        self::assertSame('/roles/42', $urls->page('role', ['id' => 42]));
        self::assertSame('/roles/42?success=ok', $urls->redirect('/roles/42?success=ok'));
    }
}
