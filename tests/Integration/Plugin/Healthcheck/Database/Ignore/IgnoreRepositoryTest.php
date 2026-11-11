<?php

/**
 * @package     Joomla.IntegrationTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Plugin\Healthcheck\Database\Ignore;

use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreRepository;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;

/**
 * Test class for the Database health check ignore repository (runs on MySQL/MariaDB and PostgreSQL).
 *
 * @package     Joomla.IntegrationTest
 * @subpackage  Healthcheck.Database
 *
 * @since       __DEPLOY_VERSION__
 */
class IgnoreRepositoryTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

    private IgnoreRepository $repo;

    public function getSchemasToLoad(): array
    {
        return ['healthcheck_database.sql'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->getDBDriver();
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__healthcheck_database_ignored'))->execute();

        $this->repo = new IgnoreRepository($db);
    }

    public function testAddAndAll(): void
    {
        $this->assertTrue($this->repo->add('table', 'HC_Orphan', 42, '2026-10-17 10:00:00'));

        $rows = $this->repo->all();
        $this->assertCount(1, $rows);
        $this->assertSame('table', $rows[0]['kind']);
        $this->assertSame('hc_orphan', $rows[0]['name']);
        $this->assertSame(42, $rows[0]['created_by']);
        $this->assertSame('2026-10-17 10:00:00', $rows[0]['created']);

        $this->assertTrue($this->repo->add('table', 'hc_orphan', 7, '2026-10-18 10:00:00'));
        $this->assertCount(1, $this->repo->all());
    }

    public function testAddColumnAndPrefix(): void
    {
        $this->assertTrue($this->repo->add('column', 'Jos_Content.created', 1, '2026-10-17 10:00:00'));
        $this->assertTrue($this->repo->add('prefix', 'kiufs_', 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('column', 'nodot', 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('column', '.created', 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('column', 'jos_content.', 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('prefix', 'a.b_', 1, '2026-10-17 10:00:00'));

        // A column splits on the last dot: table `a.b`, column `c`.
        $this->assertTrue($this->repo->add('column', 'a.b.c', 1, '2026-10-17 10:00:00'));

        $names = array_column($this->repo->all(), 'name');
        sort($names);
        $this->assertSame(['a.b.c', 'jos_content.created', 'kiufs_'], $names);
    }

    public function testAcceptsAnyTableName(): void
    {
        $longColumn = str_repeat('t', 64) . '.' . str_repeat('c', 64);
        $longest    = str_repeat('a', 255);

        $this->assertTrue($this->repo->add('table', 'hc_my-component_items', 1, '2026-10-17 10:00:00'));
        $this->assertTrue($this->repo->add('table', '#__content', 1, '2026-10-17 10:00:00'));
        $this->assertTrue($this->repo->add('table', 'a.b', 1, '2026-10-17 10:00:00'));
        $this->assertTrue($this->repo->add('table', 'x; DROP TABLE y', 1, '2026-10-17 10:00:00'));
        $this->assertTrue($this->repo->add('column', $longColumn, 1, '2026-10-17 10:00:00'));
        $this->assertTrue($this->repo->add('table', $longest, 1, '2026-10-17 10:00:00'));

        $names = array_column($this->repo->all(), 'name');
        sort($names);
        $this->assertSame(['#__content', 'a.b', $longest, 'hc_my-component_items', $longColumn, 'x; drop table y'], $names);
    }

    public function testRejectsInvalid(): void
    {
        $this->assertFalse($this->repo->add('drop', 'hc_orphan', 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('table', '', 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('prefix', 'kiufs', 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('table', str_repeat('a', 256), 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('table', "hc_orphan\n", 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('table', "hc_\x01orphan", 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('table', "hc_orphan\x7F", 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('table', str_repeat('a', 255) . "\n", 1, '2026-10-17 10:00:00'));
        $this->assertFalse($this->repo->add('table', "hc_\xC3", 1, '2026-10-17 10:00:00'));

        $this->assertSame([], $this->repo->all());
    }

    public function testRemove(): void
    {
        $this->repo->add('table', 'hc_orphan', 1, '2026-10-17 10:00:00');

        $this->assertTrue($this->repo->remove('table', 'HC_ORPHAN'));
        $this->assertSame([], $this->repo->all());
        $this->assertFalse($this->repo->remove('table', 'hc_orphan'));
    }

    public function testCollationEqualNamesAreKeptApart(): void
    {
        $mysql = $this->getDBDriver()->getServerType() === 'mysql';

        $this->assertTrue($this->repo->add('table', 'café_log', 1, '2026-10-17 10:00:00'));

        // utf8mb4_unicode_ci treats cafe_log as café_log: the UNIQUE key refuses it, and add() says so.
        $this->assertSame(!$mysql, $this->repo->add('table', 'cafe_log', 1, '2026-10-17 10:00:00'));
        $this->assertContains('café_log', array_column($this->repo->all(), 'name'));
        $this->assertSame($mysql ? ['café_log'] : ['café_log', 'cafe_log'], array_column($this->repo->all(), 'name'));

        // Reopen matches byte for byte: removing cafe_log never deletes café_log.
        $this->assertSame(!$mysql, $this->repo->remove('table', 'cafe_log'));
        $this->assertSame(['café_log'], array_column($this->repo->all(), 'name'));
        $this->assertTrue($this->repo->remove('table', 'CAFé_log'));
        $this->assertSame([], $this->repo->all());
    }

    public function testRemoveDeletesOnlyTheExactRow(): void
    {
        $this->repo->add('table', 'hc_a', 1, '2026-10-17 10:00:00');
        $this->repo->add('column', 'hc_a.created', 1, '2026-10-17 10:00:00');
        $this->repo->add('table', 'hc_b', 1, '2026-10-17 10:00:00');

        $this->assertTrue($this->repo->remove('table', 'hc_a'));
        $this->assertSame(['hc_a.created', 'hc_b'], array_column($this->repo->all(), 'name'));
    }

    public function testPadSpaceNamesAreKeptApart(): void
    {
        $mysql = $this->getDBDriver()->getServerType() === 'mysql';

        $this->assertTrue($this->repo->add('table', 'hc_b', 1, '2026-10-17 10:00:00'));

        // PAD SPACE collations take `hc_b ` as `hc_b`: refused, and Reopen of `hc_b ` leaves `hc_b` alone.
        $this->assertSame(!$mysql, $this->repo->add('table', 'hc_b ', 1, '2026-10-17 10:00:00'));
        $this->assertSame(!$mysql, $this->repo->remove('table', 'hc_b '));
        $this->assertSame(['hc_b'], array_column($this->repo->all(), 'name'));

        // A leading space is a different name everywhere.
        $this->assertTrue($this->repo->add('table', ' old_users', 1, '2026-10-17 10:00:00'));
        $this->assertTrue($this->repo->remove('table', ' old_users'));
        $this->assertSame(['hc_b'], array_column($this->repo->all(), 'name'));
    }
}
