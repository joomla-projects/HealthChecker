<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Scan;

use Joomla\Plugin\Healthcheck\Database\Catalog\Catalog;
use Joomla\Plugin\Healthcheck\Database\Catalog\MissingObjectException;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreList;
use Joomla\Plugin\Healthcheck\Database\Scan\ResultStore;
use Joomla\Plugin\Healthcheck\Database\Scan\ZeroDateScanner;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the zero-date scanner
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The zero-date scanner')]
class ZeroDateScannerTest extends UnitTestCase
{
    private const NOW = '2026-10-17 10:00:00';

    private const HOURS = 24;

    private const DEAD = 330.0;

    /**
     * A catalog with three date tables. Tables listed in `gone` are missing, tables listed in `broken` fail
     * with another error; every call is recorded in `calls`.
     *
     * @param   array  $zeros  Zero counts keyed by "table.column", 0 when absent
     * @param   array  $fake   gone, broken (RuntimeException), typeerror (TypeError) table names; present (gone
     *                         tables tableExists() still reports); columns (replaces the date columns); calls (filled in)
     *
     * @return  Catalog
     *
     * @since   __DEPLOY_VERSION__
     */
    private function catalog(array &$zeros, array &$fake = []): Catalog
    {
        $fake += ['gone' => [], 'broken' => [], 'typeerror' => [], 'present' => [], 'columns' => null, 'calls' => []];

        return new class ($zeros, $fake) implements Catalog {
            public function __construct(private array &$zeros, private array &$fake)
            {
            }

            public function tables(): array
            {
                return [];
            }

            public function supportsZeroDates(): bool
            {
                return true;
            }

            public function tableExists(string $table, array $columns = []): bool
            {
                $key = strtolower($table);

                return !\in_array($key, $this->fake['gone'], true) || \in_array($key, $this->fake['present'], true);
            }

            public function dateColumns(string $tablePrefix): array
            {
                return $this->fake['columns'] ?? [
                    ['table' => 'hc_c', 'column' => 'day', 'type' => 'date'],
                    ['table' => 'HC_A', 'column' => 'created', 'type' => 'datetime'],
                    ['table' => 'hc_b', 'column' => 'modified', 'type' => 'timestamp'],
                ];
            }

            public function zeroDateCounts(string $table, array $columns): array
            {
                $this->fake['calls'][] = [$table, $columns];
                $key                   = strtolower($table);

                if (\in_array($key, $this->fake['gone'], true)) {
                    throw new MissingObjectException('gone', 1146, new \RuntimeException('previous'));
                }

                if (\in_array($key, $this->fake['broken'], true)) {
                    throw new \RuntimeException('lost connection', 2013);
                }

                if (\in_array($key, $this->fake['typeerror'], true)) {
                    throw new \TypeError('bad argument');
                }

                $counts = [];

                foreach ($columns as $column) {
                    $counts[$column] = $this->zeros[$key . '.' . $column] ?? 0;
                }

                return $counts;
            }
        };
    }

    /**
     * A scanner whose clock moves 2 seconds per reading.
     *
     * @param   Catalog  $catalog  The catalog
     *
     * @return  ZeroDateScanner
     *
     * @since   __DEPLOY_VERSION__
     */
    private function scanner(Catalog $catalog): ZeroDateScanner
    {
        $time = 0.0;

        return new ZeroDateScanner($catalog, static function () use (&$time) {
            $time += 2.0;

            return $time;
        });
    }

    /**
     * Zero counts of the fake catalog.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function zeros(): array
    {
        return ['hc_a.created' => 2, 'hc_b.modified' => 0, 'hc_c.day' => 1];
    }

    /**
     * The findings as "table.column=zero" strings.
     *
     * @param   array  $state  The state
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function open(array $state): array
    {
        return array_map(
            static fn ($f) => $f['table'] . '.' . $f['column'] . '=' . $f['zero'],
            ZeroDateScanner::findings($state, new IgnoreList([]))
        );
    }

    #[TestDox('a pass within the budget completes in one run')]
    public function testFullPassWithinBudget()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertTrue($state['complete']);
        $this->assertSame(self::NOW, $state['updated']);
        $this->assertSame(['HC_A.created=2', 'hc_c.day=1'], $this->open($state));
        $this->assertSame(['checked' => 3, 'total' => 3], ZeroDateScanner::progress($state));
    }

    #[TestDox('a small budget splits the pass over several runs')]
    public function testBudgetSplitsPass()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 1, self::NOW, self::HOURS, self::DEAD);

        $this->assertFalse($state['complete']);
        $this->assertSame(['checked' => 1, 'total' => 3], ZeroDateScanner::progress($state));
        $this->assertSame(['HC_A.created=2'], $this->open($state));

        $state = $scanner->run($state, 'hc_', 1, self::NOW, self::HOURS, self::DEAD);
        $this->assertFalse($state['complete']);

        $state = $scanner->run($state, 'hc_', 1, self::NOW, self::HOURS, self::DEAD);
        $this->assertTrue($state['complete']);
        $this->assertSame(['checked' => 3, 'total' => 3], ZeroDateScanner::progress($state));
    }

    #[TestDox('earlier results stay visible when a new pass starts')]
    public function testMergedResultsSurviveRestart()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);
        $state = $scanner->start($state, 'hc_', self::NOW, self::HOURS, self::DEAD);

        $this->assertFalse($state['complete']);
        $this->assertSame(0, $state['cursor']);

        $state = $scanner->run($state, 'hc_', 1, self::NOW, self::HOURS, self::DEAD);

        $this->assertSame(['HC_A.created=2', 'hc_c.day=1'], $this->open($state));
    }

    #[TestDox('a fixed column disappears when its table is re-checked')]
    public function testFixedColumnDisappears()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $zeros['hc_a.created'] = 0;
        $state                 = $scanner->run($scanner->start($state, 'hc_', self::NOW, self::HOURS, self::DEAD), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertSame(['hc_c.day=1'], $this->open($state));
    }

    #[TestDox('a table dropped during the pass is skipped')]
    public function testDroppedTableIsSkipped()
    {
        $zeros   = $this->zeros();
        $fake    = ['gone' => ['hc_b']];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertTrue($state['complete']);
        $this->assertSame(['HC_A.created=2', 'hc_c.day=1'], $this->open($state));
        $this->assertArrayNotHasKey('hc_b', $state['results']);
    }

    #[TestDox('a dropped table loses its earlier result')]
    public function testDroppedTableLosesResult()
    {
        $zeros                  = $this->zeros();
        $zeros['hc_b.modified'] = 4;
        $fake                   = [];
        $scanner                = $this->scanner($this->catalog($zeros, $fake));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);
        $this->assertArrayHasKey('hc_b', $state['results']);

        $fake['gone'] = ['hc_b'];
        $state        = $scanner->run($scanner->start($state, 'hc_', self::NOW, self::HOURS, self::DEAD), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertArrayNotHasKey('hc_b', $state['results']);
    }

    #[TestDox('each table is counted with one catalog call for all its date columns')]
    public function testOneCallPerTable()
    {
        $zeros   = $this->zeros();
        $fake    = [];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertSame([['HC_A', ['created']], ['hc_b', ['modified']], ['hc_c', ['day']]], $fake['calls']);
    }

    #[TestDox('any other error keeps the result, stops the batch, does not advance and records the table')]
    public function testOtherErrorStopsWithoutAdvancing()
    {
        $zeros                  = $this->zeros();
        $zeros['hc_b.modified'] = 4;
        $fake                   = [];
        $scanner                = $this->scanner($this->catalog($zeros, $fake));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);
        $this->assertArrayHasKey('hc_b', $state['results']);

        $later          = '2026-10-17 11:00:00';
        $fake['broken'] = ['hc_b'];
        $fake['calls']  = [];
        $state          = $scanner->run($scanner->start($state, 'hc_', $later, self::HOURS, self::DEAD), 'hc_', 10, $later, self::HOURS, self::DEAD);

        $this->assertFalse($state['complete']);
        $this->assertSame(self::NOW, $state['updated'], 'the last complete pass stays the reference');
        $this->assertSame(['checked' => 1, 'total' => 3], ZeroDateScanner::progress($state));
        $this->assertSame(['table' => 'hc_b', 'at' => $later], $state['error']);
        $this->assertSame(self::NOW, $state['results']['hc_b']['checked'], 'the previous result is kept');
        $this->assertSame(['HC_A', 'hc_b'], array_column($fake['calls'], 0), 'the batch stops at the failing table');

        // Retrying fails again without advancing; the pass never completes through errors.
        $state = $scanner->run($state, 'hc_', 10, $later, self::HOURS, self::DEAD);
        $this->assertFalse($state['complete']);
        $this->assertSame(1, $state['cursor']);

        // The next successful table clears the error.
        $fake['broken'] = [];
        $state          = $scanner->run($state, 'hc_', 10, $later, self::HOURS, self::DEAD);
        $this->assertTrue($state['complete']);
        $this->assertNull($state['error']);
        $this->assertSame($later, $state['results']['hc_b']['checked']);
    }

    #[TestDox('the table is marked in progress, and handed to the callback, before the catalog is asked')]
    public function testInProgressMarker()
    {
        $zeros   = $this->zeros();
        $fake    = [];
        $scanner = $this->scanner($this->catalog($zeros, $fake));
        $seen    = [];

        $state = $scanner->run(
            ZeroDateScanner::emptyState(),
            'hc_',
            10,
            self::NOW,
            self::HOURS,
            self::DEAD,
            static function (array $state) use (&$seen, &$fake): void {
                $seen[] = [$state['inprogress']['key'], \is_float($state['inprogress']['at']), \count($fake['calls'])];
            }
        );

        $this->assertSame([['hc_a', true, 0], ['hc_b', true, 1], ['hc_c', true, 2]], $seen);
        $this->assertNull($state['inprogress']);
    }

    #[TestDox('a table a request died on is skipped as too large, and the pass moves on')]
    public function testDeadRequestTableIsSkipped()
    {
        $zeros   = $this->zeros();
        $fake    = [];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        // A run that died while counting hc_b left this state behind, longer ago than DEAD.
        $state               = $scanner->start(ZeroDateScanner::emptyState(), 'hc_', self::NOW, self::HOURS, self::DEAD);
        $state['cursor']     = 1;
        $state['inprogress'] = ['key' => 'hc_b', 'at' => -1000.0];

        $this->assertFalse(ZeroDateScanner::isBusy($state, 2.0, self::DEAD));

        $state = $scanner->run($state, 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertTrue($state['complete']);
        $this->assertSame(['hc_b' => ['name' => 'hc_b', 'at' => self::NOW, 'reason' => 'large']], $state['skipped']);
        $this->assertSame([['hc_c', ['day']]], $fake['calls']);
        $this->assertNull($state['inprogress']);
    }

    #[TestDox('a marker younger than the dead-after time means another request is counting: busy, nothing changes')]
    public function testLiveMarkerIsBusy()
    {
        $zeros   = $this->zeros();
        $fake    = [];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        $state               = $scanner->start(ZeroDateScanner::emptyState(), 'hc_', self::NOW, self::HOURS, self::DEAD);
        $state['cursor']     = 1;
        $state['inprogress'] = ['key' => 'hc_b', 'at' => 0.0];

        $this->assertTrue(ZeroDateScanner::isBusy($state, 2.0, self::DEAD));
        $this->assertTrue(ZeroDateScanner::isBusy($state, 329.9, self::DEAD));
        $this->assertFalse(ZeroDateScanner::isBusy($state, 330.0, self::DEAD));

        // A marker for another table than the cursor one is not a live count.
        $other           = $state;
        $other['cursor'] = 2;
        $this->assertFalse(ZeroDateScanner::isBusy($other, 2.0, self::DEAD));

        // run() leaves a live pass alone: no count, no skip, no cursor move.
        $after = $scanner->run($state, 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertSame([], $fake['calls']);
        $this->assertSame([], $after['skipped']);
        $this->assertSame(1, $after['cursor']);
        $this->assertSame(['key' => 'hc_b', 'at' => 0.0], $after['inprogress']);
    }

    #[TestDox('the dead-after time covers the budget and the PHP time limit, plus a margin')]
    public function testDeadAfter()
    {
        $this->assertSame(60.0, ZeroDateScanner::deadAfter(5, 30));
        $this->assertSame(330.0, ZeroDateScanner::deadAfter(5, 0), 'unlimited counts as 300 seconds');
        $this->assertSame(50.0, ZeroDateScanner::deadAfter(20, 10));
    }

    #[TestDox('a new pass keeps tables skipped within the refresh hours and retries older ones')]
    public function testStartKeepsRecentSkipped()
    {
        $zeros   = $this->zeros();
        $fake    = [];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        $state            = ZeroDateScanner::emptyState();
        $state['skipped'] = [
            'hc_a' => ['name' => 'HC_A', 'at' => '2026-10-16 11:00:00'],
            'hc_b' => ['name' => 'hc_b', 'at' => '2026-10-16 09:00:00'],
        ];

        $state = $scanner->start($state, 'hc_', self::NOW, self::HOURS, self::DEAD);

        $this->assertSame(['hc_a' => ['name' => 'HC_A', 'at' => '2026-10-16 11:00:00', 'reason' => 'large']], $state['skipped']);

        // The kept table is passed over without being counted; the dropped one is counted again.
        $state = $scanner->run($state, 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertTrue($state['complete']);
        $this->assertSame(['hc_b', 'hc_c'], array_column($fake['calls'], 0));
        $this->assertSame(['hc_a'], array_keys($state['skipped']));
        $this->assertSame(['checked' => 3, 'total' => 3], ZeroDateScanner::progress($state));
    }

    #[TestDox('Check now after a request died on a huge table keeps it skipped, so the pass completes')]
    public function testRestartAfterDeathCompletes()
    {
        $zeros   = $this->zeros();
        $fake    = [];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        // Pass 1 dies while counting hc_b: the marker was stored before the count.
        $stored = null;
        $state  = $scanner->run(
            ZeroDateScanner::emptyState(),
            'hc_',
            0,
            self::NOW,
            self::HOURS,
            self::DEAD,
            static function (array $state) use (&$stored): void {
                $stored = $state;
            }
        );
        $state = $scanner->run($state, 'hc_', 0, self::NOW, self::HOURS, self::DEAD, static function (array $state) use (&$stored): void {
            $stored = $state;
        });
        $this->assertSame(2, $state['cursor']);

        $died                     = $stored;
        $died['inprogress']['at'] = -1000.0;
        $this->assertSame('hc_b', $died['inprogress']['key']);
        $this->assertSame(1, $died['cursor']);

        // Pass 2 (the next request) skips it and finishes.
        $state = $scanner->run($died, 'hc_', 10, self::NOW, self::HOURS, self::DEAD);
        $this->assertTrue($state['complete']);
        $this->assertSame(['hc_b'], array_keys($state['skipped']));

        // Check now: a new pass keeps hc_b skipped and completes without counting it.
        $later         = '2026-10-17 10:30:00';
        $fake['calls'] = [];
        $state         = $scanner->run($scanner->start($state, 'hc_', $later, self::HOURS, self::DEAD), 'hc_', 10, $later, self::HOURS, self::DEAD);

        $this->assertTrue($state['complete']);
        $this->assertSame($later, $state['updated']);
        $this->assertSame(['hc_b'], array_keys($state['skipped']));
        $this->assertSame(['HC_A', 'hc_c'], array_column($fake['calls'], 0));
    }

    #[TestDox('Check now straight after a request died (no batch in between) skips the table it died on')]
    public function testRestartOnDeadMarkerSkipsTable()
    {
        $zeros   = $this->zeros();
        $fake    = [];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        // Check now died while counting hc_b: its marker is the last thing stored.
        $died               = $scanner->start(ZeroDateScanner::emptyState(), 'hc_', self::NOW, self::HOURS, self::DEAD);
        $died['cursor']     = 1;
        $died['inprogress'] = ['key' => 'hc_b', 'at' => -1000.0];

        // The next Check now restarts at once.
        $later = '2026-10-17 10:10:00';
        $state = $scanner->start($died, 'hc_', $later, self::HOURS, self::DEAD);

        $this->assertSame(['hc_b' => ['name' => 'hc_b', 'at' => $later, 'reason' => 'large']], $state['skipped']);
        $this->assertNull($state['inprogress']);
        $this->assertSame(0, $state['cursor']);

        $state = $scanner->run($state, 'hc_', 10, $later, self::HOURS, self::DEAD);

        $this->assertTrue($state['complete']);
        $this->assertSame(['HC_A', 'hc_c'], array_column($fake['calls'], 0));
        $this->assertSame(['hc_b'], array_keys($state['skipped']));

        // A live marker is not turned into a skip by start() (callers defer instead).
        $live               = $died;
        $live['inprogress'] = ['key' => 'hc_b', 'at' => 0.0];
        $this->assertSame([], $scanner->start($live, 'hc_', $later, self::HOURS, self::DEAD)['skipped']);
    }

    #[TestDox('a missing-object error on a table that still exists is an error, not a dropped table')]
    public function testMissingButPresentIsError()
    {
        $zeros                  = $this->zeros();
        $zeros['hc_b.modified'] = 4;
        $fake                   = [];
        $scanner                = $this->scanner($this->catalog($zeros, $fake));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);
        $this->assertArrayHasKey('hc_b', $state['results']);

        // A name the query mangles fails with "unknown column" although the table and column are there.
        $fake['gone']    = ['hc_b'];
        $fake['present'] = ['hc_b'];
        $state           = $scanner->run($scanner->start($state, 'hc_', self::NOW, self::HOURS, self::DEAD), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertFalse($state['complete']);
        $this->assertSame(['table' => 'hc_b', 'at' => self::NOW], $state['error']);
        $this->assertArrayHasKey('hc_b', $state['results'], 'the previous result is kept');
        $this->assertNull($state['inprogress']);
    }

    #[TestDox('a table or date column whose name holds #__ or a :placeholder is never counted and is skipped for its name')]
    public function testHashPrefixNamesAreSkipped()
    {
        $zeros           = $this->zeros();
        $fake            = ['columns' => [
            ['table' => 'hc_a', 'column' => 'created', 'type' => 'datetime'],
            ['table' => 'hc_old#__users', 'column' => 'created', 'type' => 'datetime'],
            ['table' => 'hc_c', 'column' => 'day', 'type' => 'date'],
            ['table' => 'hc_c', 'column' => 'x#__y', 'type' => 'date'],
            ['table' => 'hc_log:2020', 'column' => 'created', 'type' => 'datetime'],
            ['table' => 'hc_d', 'column' => 'at:_x', 'type' => 'date'],
            ['table' => 'hc_e', 'column' => 'a: b', 'type' => 'date'],
        ]];
        $scanner                  = $this->scanner($this->catalog($zeros, $fake));
        $state                    = ZeroDateScanner::emptyState();
        $state['results']['hc_c'] = ['table' => 'hc_c', 'checked' => '2026-10-01 00:00:00', 'columns' => [['column' => 'day', 'type' => 'date', 'zero' => 1]]];

        $state = $scanner->start($state, 'hc_', self::NOW, self::HOURS, self::DEAD);

        $this->assertSame(
            [
                'hc_c'           => ['name' => 'hc_c', 'at' => self::NOW, 'reason' => 'name'],
                'hc_d'           => ['name' => 'hc_d', 'at' => self::NOW, 'reason' => 'name'],
                'hc_log:2020'    => ['name' => 'hc_log:2020', 'at' => self::NOW, 'reason' => 'name'],
                'hc_old#__users' => ['name' => 'hc_old#__users', 'at' => self::NOW, 'reason' => 'name'],
            ],
            $state['skipped']
        );
        $this->assertArrayNotHasKey('hc_c', $state['results'], 'a result from a mangled query is not kept');

        $state = $scanner->run($state, 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertTrue($state['complete']);
        // A colon not followed by a letter, digit or underscore is not a placeholder: hc_e is counted.
        $this->assertSame(['hc_a', 'hc_e'], array_column($fake['calls'], 0));
        $this->assertSame(['hc_c', 'hc_d', 'hc_log:2020', 'hc_old#__users'], array_keys($state['skipped']));

        // Rebuilt on every start: never retried as a count, never kept once the name is gone.
        $later           = '2026-10-20 10:00:00';
        $fake['columns'] = [['table' => 'hc_a', 'column' => 'created', 'type' => 'datetime']];
        $this->assertSame([], $scanner->start($state, 'hc_', $later, self::HOURS, self::DEAD)['skipped']);
    }

    #[TestDox('an Error that is not a RuntimeException is recorded like one and clears the marker')]
    public function testThrowableStopsRun()
    {
        $zeros   = $this->zeros();
        $fake    = ['typeerror' => ['hc_b']];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        $state = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertFalse($state['complete']);
        $this->assertSame(['table' => 'hc_b', 'at' => self::NOW], $state['error']);
        $this->assertNull($state['inprogress']);
        $this->assertSame(1, $state['cursor']);
        $this->assertSame(['HC_A', 'hc_b'], array_column($fake['calls'], 0));
    }

    #[TestDox('old cached shapes (string marker, string skipped values) do not fail')]
    public function testOldShapesTolerated()
    {
        $zeros   = $this->zeros();
        $fake    = [];
        $scanner = $this->scanner($this->catalog($zeros, $fake));

        $state               = $scanner->start(ZeroDateScanner::emptyState(), 'hc_', self::NOW, self::HOURS, self::DEAD);
        $state['cursor']     = 1;
        $state['inprogress'] = 'hc_b';
        $state['skipped']    = ['hc_c' => 'hc_c'];

        $this->assertFalse(ZeroDateScanner::isBusy($state, 2.0, self::DEAD));

        // A string marker counts as absent; a string skipped value is still passed over in this pass.
        $state = $scanner->run($state, 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertTrue($state['complete']);
        $this->assertSame(['hc_b'], array_column($fake['calls'], 0));
        $this->assertSame(['hc_c' => ['name' => 'hc_c', 'at' => null, 'reason' => 'large']], $state['skipped']);

        // Without a time it is retried on the next start.
        $state            = $scanner->start($state, 'hc_', self::NOW, self::HOURS, self::DEAD);
        $this->assertSame([], $state['skipped']);

        $old              = $scanner->start(ZeroDateScanner::emptyState(), 'hc_', self::NOW, self::HOURS, self::DEAD);
        $old['skipped']   = ['hc_c' => 'hc_c'];
        $this->assertSame([], $scanner->start($old, 'hc_', self::NOW, self::HOURS, self::DEAD)['skipped']);
    }

    #[TestDox('a stale in-progress marker for another table is dropped')]
    public function testStaleMarkerDropped()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state               = $scanner->start(ZeroDateScanner::emptyState(), 'hc_', self::NOW, self::HOURS, self::DEAD);
        $state['inprogress'] = ['key' => 'hc_c', 'at' => 0.0];

        $state = $scanner->run($state, 'hc_', 1, self::NOW, self::HOURS, self::DEAD);

        $this->assertSame([], $state['skipped']);
        $this->assertSame(['checked' => 1, 'total' => 3], ZeroDateScanner::progress($state));
    }

    #[TestDox('a result for a table that is gone is pruned when the pass completes')]
    public function testTableNoLongerPresentIsPruned()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));

        $state                       = ZeroDateScanner::emptyState();
        $state['results']['hc_old']  = [
            'table'   => 'hc_old',
            'checked' => '2026-10-01 00:00:00',
            'columns' => [['column' => 'x', 'type' => 'date', 'zero' => 5]],
        ];

        $state = $scanner->run($state, 'hc_', 10, self::NOW, self::HOURS, self::DEAD);

        $this->assertArrayNotHasKey('hc_old', $state['results']);
    }

    #[TestDox('ignored columns are flagged and counted apart')]
    public function testIgnoredColumns()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));
        $state   = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 10, self::NOW, self::HOURS, self::DEAD);
        $ignored = new IgnoreList([['kind' => 'column', 'name' => 'hc_a.created']]);

        $this->assertSame(1, ZeroDateScanner::openCount($state, $ignored));
        $this->assertSame(1, ZeroDateScanner::ignoredCount($state, $ignored));

        $flags = array_column(ZeroDateScanner::findings($state, $ignored), 'ignored', 'column');

        $this->assertTrue($flags['created']);
        $this->assertFalse($flags['day']);
    }

    #[TestDox('the state survives a JSON round trip')]
    public function testStateIsJsonFriendly()
    {
        $zeros   = $this->zeros();
        $scanner = $this->scanner($this->catalog($zeros));
        $state   = $scanner->run(ZeroDateScanner::emptyState(), 'hc_', 1, self::NOW, self::HOURS, self::DEAD);

        $this->assertSame($state, json_decode(json_encode($state), true));
    }

    #[TestDox('staleness follows the refresh hours')]
    public function testIsStale()
    {
        $this->assertTrue(ResultStore::isStale(null, 24, self::NOW));
        $this->assertFalse(ResultStore::isStale('2026-10-16 11:00:00', 24, self::NOW));
        $this->assertTrue(ResultStore::isStale('2026-10-16 09:00:00', 24, self::NOW));
        $this->assertTrue(ResultStore::isStale('2026-10-16 10:00:00', 24, self::NOW));
        $this->assertTrue(ResultStore::isStale('garbage', 24, self::NOW));
    }
}
