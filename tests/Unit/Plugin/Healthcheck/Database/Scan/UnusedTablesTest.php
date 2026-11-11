<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Scan;

use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreList;
use Joomla\Plugin\Healthcheck\Database\Scan\UnusedTables;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the unused table grouping
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The unused table grouping')]
class UnusedTablesTest extends UnitTestCase
{
    private const ACCOUNTED = ['#__content', '#__foo_items'];

    private const SET = ['hc_content', 'hc_foo_items', 'hc_orphan', 'kiufs_content', 'kiufs_users', 'legacy'];

    /**
     * Catalog rows for the names.
     *
     * @param   string[]  $names  Table names
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function tables(array $names): array
    {
        return array_map(
            static fn ($name) => ['name' => $name, 'rows' => 1, 'bytes' => 1024, 'updated' => null],
            $names
        );
    }

    /**
     * Table names per group prefix.
     *
     * @param   array  $groups  Groups
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function names(array $groups): array
    {
        $out = [];

        foreach ($groups as $group) {
            $out[$group['prefix']] = array_column($group['tables'], 'name');
        }

        return $out;
    }

    #[TestDox('Tables are grouped by prefix with the own group first')]
    public function testGroups(): void
    {
        $groups = UnusedTables::groups($this->tables(self::SET), self::ACCOUNTED, 'hc_', new IgnoreList([]));

        $this->assertSame(['hc_', '', 'kiufs_'], array_column($groups, 'prefix'));
        $this->assertSame([true, false, false], array_column($groups, 'own'));
        $this->assertSame(
            ['hc_' => ['hc_orphan'], '' => ['legacy'], 'kiufs_' => ['kiufs_content', 'kiufs_users']],
            $this->names($groups)
        );
        $this->assertSame(4, UnusedTables::openCount($groups));
        $this->assertSame(0, UnusedTables::ignoredCount($groups));
    }

    #[TestDox('Matching against the accounted list ignores case')]
    public function testCaseInsensitiveMatch(): void
    {
        $groups = UnusedTables::groups($this->tables(['hc_Foo_Items']), ['#__foo_items'], 'hc_', new IgnoreList([]));

        $this->assertSame([], $groups);
    }

    #[TestDox('The site prefix wins over other groups, and an empty prefix works')]
    public function testSitePrefixWins(): void
    {
        $groups = UnusedTables::groups($this->tables(['jos_old_content', 'jos_content']), ['#__content'], 'jos_', new IgnoreList([]));

        $this->assertSame(['jos_' => ['jos_old_content']], $this->names($groups));
        $this->assertTrue($groups[0]['own']);
        $this->assertFalse($groups[0]['prefixIgnored']);

        $groups = UnusedTables::groups($this->tables(['content', 'stray']), ['#__content'], '', new IgnoreList([]));

        $this->assertSame(['' => ['stray']], $this->names($groups));
        $this->assertTrue($groups[0]['own']);
    }

    #[TestDox('Ignored tables and prefixes are filtered from the open count')]
    public function testIgnoreFiltering(): void
    {
        $ignored = new IgnoreList([
            ['kind' => 'table', 'name' => 'hc_orphan'],
            ['kind' => 'prefix', 'name' => 'kiufs_'],
        ]);
        $groups = UnusedTables::groups($this->tables(self::SET), self::ACCOUNTED, 'hc_', $ignored);

        $this->assertSame(1, UnusedTables::openCount($groups));
        $this->assertSame(3, UnusedTables::ignoredCount($groups));
        $this->assertFalse($groups[0]['prefixIgnored']);
        $this->assertTrue($groups[0]['tables'][0]['ignored']);
        $this->assertTrue($groups[2]['prefixIgnored']);
        $this->assertSame([true, true], array_column($groups[2]['tables'], 'ignored'));
    }

    #[TestDox('Stale and overlapping entries are not counted')]
    public function testStaleAndOverlappingEntries(): void
    {
        $entries = [
            ['kind' => 'table', 'name' => 'gone_table'],
            ['kind' => 'table', 'name' => 'kiufs_users'],
            ['kind' => 'prefix', 'name' => 'kiufs_'],
        ];
        $groups = UnusedTables::groups($this->tables(self::SET), self::ACCOUNTED, 'hc_', new IgnoreList($entries));

        $this->assertSame(2, UnusedTables::openCount($groups));
        $this->assertSame(2, UnusedTables::ignoredCount($groups));

        array_pop($entries);
        $groups = UnusedTables::groups($this->tables(self::SET), self::ACCOUNTED, 'hc_', new IgnoreList($entries));

        $this->assertSame(3, UnusedTables::openCount($groups));
        $this->assertSame(1, UnusedTables::ignoredCount($groups));
        $this->assertSame([false, true], array_column($groups[2]['tables'], 'ignored'));
    }

    #[TestDox('A prefix entry does not silence the site\'s own tables')]
    public function testPrefixEntryDoesNotSilenceOwnTables(): void
    {
        $ignored = new IgnoreList([['kind' => 'prefix', 'name' => 'abc_']]);
        $groups  = UnusedTables::groups($this->tables(['abc_x_orphan', 'abc_old']), [], 'abc_x_', $ignored);

        $this->assertSame(['abc_x_', 'abc_'], array_column($groups, 'prefix'));
        $this->assertFalse($groups[0]['tables'][0]['ignored']);
        $this->assertTrue($groups[1]['prefixIgnored']);
        $this->assertTrue($groups[1]['tables'][0]['ignored']);
        $this->assertSame(1, UnusedTables::openCount($groups));
    }
}
