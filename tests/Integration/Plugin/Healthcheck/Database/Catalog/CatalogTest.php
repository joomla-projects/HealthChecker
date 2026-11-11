<?php

/**
 * @package     Joomla.IntegrationTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Plugin\Healthcheck\Database\Catalog;

use Joomla\Plugin\Healthcheck\Database\Catalog\CatalogFactory;
use Joomla\Plugin\Healthcheck\Database\Catalog\MissingObjectException;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;

/**
 * Test class for the Database health check catalog (runs on MySQL/MariaDB and PostgreSQL).
 *
 * @package     Joomla.IntegrationTest
 * @subpackage  Healthcheck.Database
 *
 * @since       __DEPLOY_VERSION__
 */
class CatalogTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

    private const TABLES = ['jos84_hcdb_a', 'jos84_hcdb_d', 'jos84Xhcdb_z'];

    public function getSchemasToLoad(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function exec(string $sql): void
    {
        $this->getDBDriver()->setQuery($sql)->execute();
    }

    private function isMysql(): bool
    {
        return $this->getDBDriver()->getServerType() === 'mysql';
    }

    private function cleanup(): void
    {
        $db = $this->getDBDriver();
        $this->exec('DROP VIEW IF EXISTS ' . $db->quoteName('jos84_hcdb_v'));

        foreach (self::TABLES as $table) {
            $this->exec('DROP TABLE IF EXISTS ' . $db->quoteName($table));
        }

        if ($this->isMysql()) {
            // quoteName() would split the dotted name.
            $this->exec('DROP TABLE IF EXISTS `jos84_hcdb_x.y`');
        }
    }

    public function testTablesListsBaseTablesOnly(): void
    {
        $db = $this->getDBDriver();
        $a  = $db->quoteName('jos84_hcdb_a');
        $this->exec('CREATE TABLE ' . $a . ' (id INT NOT NULL, PRIMARY KEY (id))');
        $this->exec('INSERT INTO ' . $a . ' (id) VALUES (1), (2), (3)');
        $this->exec('CREATE VIEW ' . $db->quoteName('jos84_hcdb_v') . ' AS SELECT id FROM ' . $a);
        $this->exec($this->isMysql() ? 'ANALYZE TABLE ' . $a : 'ANALYZE ' . $a);

        $tables = CatalogFactory::for($db)->tables();
        $byName = [];

        foreach ($tables as $row) {
            $this->assertSame(['name', 'rows', 'bytes', 'updated'], array_keys($row));
            $byName[$row['name']] = $row;
        }

        $this->assertArrayHasKey('jos84_hcdb_a', $byName);
        $this->assertArrayNotHasKey('jos84_hcdb_v', $byName);
        $this->assertGreaterThanOrEqual(0, $byName['jos84_hcdb_a']['rows']);
        $this->assertGreaterThan(0, $byName['jos84_hcdb_a']['bytes']);
    }

    public function testUpdatedIsUtc(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('Only MySQL/MariaDB report a table update time.');
        }

        $db = $this->getDBDriver();
        $a  = $db->quoteName('jos84_hcdb_a');
        $this->exec('CREATE TABLE ' . $a . ' (id INT NOT NULL, PRIMARY KEY (id))');
        $this->exec('INSERT INTO ' . $a . ' (id) VALUES (1)');

        $updated = null;

        foreach (CatalogFactory::for($db)->tables() as $row) {
            if ($row['name'] === 'jos84_hcdb_a') {
                $updated = $row['updated'];
            }
        }

        $this->assertNotNull($updated);
        $this->assertLessThan(300, abs(strtotime($updated . ' UTC') - time()), 'updated must be UTC, was ' . $updated);
    }

    public function testDateColumnsAndZeroCount(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('Zero dates exist on MySQL/MariaDB only.');
        }

        $db      = $this->getDBDriver();
        $catalog = CatalogFactory::for($db);
        $d       = $db->quoteName('jos84_hcdb_d');
        $mode    = (string) $db->setQuery('SELECT @@SESSION.sql_mode')->loadResult();

        $this->exec("SET SESSION sql_mode = ''");

        try {
            $this->exec('CREATE TABLE ' . $d . ' (created DATETIME NULL, `day` DATE NULL, stamp TIMESTAMP NULL DEFAULT NULL)');
            $this->exec('INSERT INTO ' . $d . " (created, `day`, stamp) VALUES ('0000-00-00 00:00:00', '0000-00-00', NULL),"
                . " ('2026-10-17 10:00:00', '2026-10-17', '2026-10-17 10:00:00'), (NULL, NULL, NULL)");
            $this->exec('INSERT INTO ' . $d . " (created, `day`, stamp) VALUES ('0000-00-00 00:00:00', '2026-10-17', '0000-00-00 00:00:00')");

            $columns = $catalog->dateColumns('jos84_hcdb_');
            $this->assertSame([
                ['table' => 'jos84_hcdb_d', 'column' => 'created', 'type' => 'datetime'],
                ['table' => 'jos84_hcdb_d', 'column' => 'day', 'type' => 'date'],
                ['table' => 'jos84_hcdb_d', 'column' => 'stamp', 'type' => 'timestamp'],
            ], $columns);

            $check = function () use ($catalog): void {
                $this->assertSame(
                    ['created' => 2, 'day' => 1, 'stamp' => 1],
                    $catalog->zeroDateCounts('jos84_hcdb_d', ['created', 'day', 'stamp'])
                );
            };

            $check();
        } finally {
            $this->exec('SET SESSION sql_mode = ' . $db->quote($mode));
        }

        $check();
        $this->assertTrue($catalog->supportsZeroDates());
    }

    public function testDottedNamesAreCountedAndFound(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('Zero dates exist on MySQL/MariaDB only.');
        }

        $db      = $this->getDBDriver();
        $catalog = CatalogFactory::for($db);
        $mode    = (string) $db->setQuery('SELECT @@SESSION.sql_mode')->loadResult();

        $this->exec("SET SESSION sql_mode = ''");

        try {
            $this->exec('CREATE TABLE `jos84_hcdb_x.y` (`c.d` DATETIME NULL, `a``b` DATE NULL)');
            $this->exec("INSERT INTO `jos84_hcdb_x.y` (`c.d`, `a``b`) VALUES ('0000-00-00 00:00:00', '0000-00-00'), (NULL, '2026-10-17')");
        } finally {
            $this->exec('SET SESSION sql_mode = ' . $db->quote($mode));
        }

        $this->assertSame(
            [
                ['table' => 'jos84_hcdb_x.y', 'column' => 'c.d', 'type' => 'datetime'],
                ['table' => 'jos84_hcdb_x.y', 'column' => 'a`b', 'type' => 'date'],
            ],
            $catalog->dateColumns('jos84_hcdb_')
        );
        $this->assertSame(['c.d' => 1, 'a`b' => 1], $catalog->zeroDateCounts('jos84_hcdb_x.y', ['c.d', 'a`b']));

        $this->assertTrue($catalog->tableExists('jos84_hcdb_x.y', ['C.D', 'a`b']));
        $this->assertTrue($catalog->tableExists('jos84_hcdb_x.y'));
        $this->assertFalse($catalog->tableExists('jos84_hcdb_x.y', ['c.d', 'gone']));
        $this->assertFalse($catalog->tableExists('jos84_hcdb_gone'));
    }

    public function testPrefixLikeIsEscaped(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('dateColumns() is MySQL/MariaDB only.');
        }

        $db = $this->getDBDriver();
        $this->exec('CREATE TABLE ' . $db->quoteName('jos84_hcdb_d') . ' (created DATETIME NULL)');
        $this->exec('CREATE TABLE ' . $db->quoteName('jos84Xhcdb_z') . ' (created DATETIME NULL)');

        $tables = array_unique(array_column(CatalogFactory::for($db)->dateColumns('jos84_hcdb_'), 'table'));

        $this->assertSame(['jos84_hcdb_d'], array_values($tables));
    }

    public function testMissingTableThrows(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('PostgreSQL never counts zero dates (see testPostgresHasNoZeroDates).');
        }

        try {
            CatalogFactory::for($this->getDBDriver())->zeroDateCounts('jos84_hcdb_gone', ['x']);
            $this->fail('A missing table must throw');
        } catch (MissingObjectException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testMissingColumnThrows(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('PostgreSQL never counts zero dates (see testPostgresHasNoZeroDates).');
        }

        $db = $this->getDBDriver();
        $this->exec('CREATE TABLE ' . $db->quoteName('jos84_hcdb_d') . ' (created DATETIME NULL)');

        try {
            CatalogFactory::for($db)->zeroDateCounts('jos84_hcdb_d', ['created', 'gone']);
            $this->fail('A missing column must throw');
        } catch (MissingObjectException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testEmptyTableCountsZero(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('Zero dates exist on MySQL/MariaDB only.');
        }

        $db = $this->getDBDriver();
        $this->exec('CREATE TABLE ' . $db->quoteName('jos84_hcdb_d') . ' (created DATETIME NULL, `day` DATE NULL)');

        $this->assertSame(['created' => 0, 'day' => 0], CatalogFactory::for($db)->zeroDateCounts('jos84_hcdb_d', ['created', 'day']));
    }

    public function testDateColumnsSkipViews(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('dateColumns() is MySQL/MariaDB only.');
        }

        $db = $this->getDBDriver();
        $this->exec('CREATE TABLE ' . $db->quoteName('jos84_hcdb_d') . ' (created DATETIME NULL)');
        $this->exec('CREATE VIEW ' . $db->quoteName('jos84_hcdb_v') . ' AS SELECT created FROM ' . $db->quoteName('jos84_hcdb_d'));

        $tables = array_values(array_unique(array_column(CatalogFactory::for($db)->dateColumns('jos84_hcdb_'), 'table')));

        $this->assertSame(['jos84_hcdb_d'], $tables);
    }

    public function testPostgresHasNoZeroDates(): void
    {
        $catalog = CatalogFactory::for($this->getDBDriver());

        if ($this->isMysql()) {
            $this->markTestSkipped('PostgreSQL only.');
        }

        $this->assertFalse($catalog->supportsZeroDates());
        $this->assertSame([], $catalog->dateColumns('jos84_hcdb_'));

        $this->exec('CREATE TABLE ' . $this->getDBDriver()->quoteName('jos84_hcdb_a') . ' (id INT NOT NULL)');
        $this->assertTrue($catalog->tableExists('jos84_hcdb_a', ['ID']));
        $this->assertFalse($catalog->tableExists('jos84_hcdb_a', ['gone']));
        $this->assertFalse($catalog->tableExists('jos84_hcdb_gone'));
        $this->assertSame(['x' => 0], $catalog->zeroDateCounts('jos84_hcdb_gone', ['x']));
    }
}
