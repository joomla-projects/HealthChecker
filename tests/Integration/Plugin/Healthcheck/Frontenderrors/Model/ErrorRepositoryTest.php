<?php

/**
 * @package     Joomla.IntegrationTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Plugin\Healthcheck\Frontenderrors\Model;

use Joomla\Database\ParameterType;
use Joomla\Plugin\Healthcheck\Frontenderrors\Model\ErrorRepository;
use Joomla\Plugin\Healthcheck\Frontenderrors\Report\ReportNormaliser;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;

/**
 * Test class for the Frontend Errors repository (runs on MySQL/MariaDB and PostgreSQL).
 *
 * @package     Joomla.IntegrationTest
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @since       __DEPLOY_VERSION__
 */
class ErrorRepositoryTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

    private const ROOT = 'https://example.org/';

    private ErrorRepository $repo;

    public function getSchemasToLoad(): array
    {
        return ['frontenderrors.sql'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->getDBDriver();

        foreach (['#__frontenderrors_hits', '#__frontenderrors'] as $table) {
            $db->setQuery('DELETE FROM ' . $db->quoteName($table))->execute();
        }

        $this->repo = new ErrorRepository($db);
    }

    /**
     * Build one normalised report.
     */
    private function report(string $message = 'boom', string $page = '/a', int $count = 1, string $file = '/media/com_foo/js/a.js'): array
    {
        $out = ReportNormaliser::normalise(
            [
                'page'    => $page,
                'reports' => [[
                    'type'    => 'error',
                    'message' => $message,
                    'file'    => $file,
                    'line'    => 3,
                    'col'     => 4,
                    'stack'   => 'at x',
                    'count'   => $count,
                ]],
            ],
            self::ROOT
        );

        $this->assertCount(1, $out);

        return $out[0];
    }

    private function id(string $message): int
    {
        $db = $this->getDBDriver();
        $fp = $this->report($message)['fingerprint'];
        $q  = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__frontenderrors'))
            ->where($db->quoteName('fingerprint') . ' = :fp')
            ->bind(':fp', $fp);

        return (int) $db->setQuery($q)->loadResult();
    }

    private function rows(string $table): int
    {
        $db = $this->getDBDriver();

        return (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($table))->loadResult();
    }

    private function row(int $id): object
    {
        $db = $this->getDBDriver();
        $q  = $db->createQuery()
            ->select('*')
            ->from($db->quoteName('#__frontenderrors'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $db->setQuery($q)->loadObject();
    }

    /**
     * Direct insert of an error row for purge/cap seeding.
     */
    private function seedError(string $fingerprint, int $state, string $firstSeen, ?string $stateChanged = null): int
    {
        $db   = $this->getDBDriver();
        $type = 'error';
        $q    = $db->createQuery()
            ->insert($db->quoteName('#__frontenderrors'))
            ->columns($db->quoteName(['fingerprint', 'type', 'stack', 'first_seen', 'last_seen', 'state', 'state_changed']))
            ->values(':fp, :type, :stack, :fs, :ls, :st, :sc')
            ->bind(':fp', $fingerprint)
            ->bind(':type', $type)
            ->bind(':stack', $type)
            ->bind(':fs', $firstSeen)
            ->bind(':ls', $firstSeen)
            ->bind(':st', $state, ParameterType::INTEGER)
            ->bind(':sc', $stateChanged, $stateChanged === null ? ParameterType::NULL : ParameterType::STRING);
        $db->setQuery($q)->execute();

        return (int) $db->insertid();
    }

    private function seedHit(int $errorId, string $page, string $day): void
    {
        $db   = $this->getDBDriver();
        $hash = sha1($page);
        $o    = 'visitor';
        $n    = 1;
        $q    = $db->createQuery()
            ->insert($db->quoteName('#__frontenderrors_hits'))
            ->columns($db->quoteName(['error_id', 'page', 'page_hash', 'day', 'origin', 'hits']))
            ->values(':id, :page, :hash, :day, :o, :n')
            ->bind(':id', $errorId, ParameterType::INTEGER)
            ->bind(':page', $page)
            ->bind(':hash', $hash)
            ->bind(':day', $day)
            ->bind(':o', $o)
            ->bind(':n', $n, ParameterType::INTEGER);
        $db->setQuery($q)->execute();
    }

    public function testRecordCreatesAndIncrements(): void
    {
        $this->assertSame(1, $this->repo->record([$this->report('boom', '/a', 2)], 'visitor', '2026-10-16 10:00:00'));
        $this->assertSame(1, $this->repo->record([$this->report('boom', '/a', 3)], 'visitor', '2026-10-16 11:00:00'));

        $this->assertSame(1, $this->rows('#__frontenderrors'));
        $this->assertSame(1, $this->rows('#__frontenderrors_hits'));

        $row = $this->row($this->id('boom'));
        $this->assertSame('2026-10-16 10:00:00', $row->first_seen);
        $this->assertSame('2026-10-16 11:00:00', $row->last_seen);
        $this->assertSame('com_foo', $row->source_extension);
        $this->assertSame(0, (int) $row->state);

        $db = $this->getDBDriver();
        $this->assertSame(5, (int) $db->setQuery('SELECT SUM(' . $db->quoteName('hits') . ') FROM ' . $db->quoteName('#__frontenderrors_hits'))->loadResult());
    }

    public function testLongSourceExtensionIsTruncated(): void
    {
        $host = str_repeat('h', 120) . '.example';
        $long = '/media/com_' . str_repeat('x', 150) . '/a.js';
        $this->assertSame(1, $this->repo->record([$this->report('ext1', '/a', 1, 'https://' . $host . '/x.js')], 'visitor', '2026-10-16 10:00:00'));
        $this->assertSame(1, $this->repo->record([$this->report('ext2', '/a', 1, $long)], 'visitor', '2026-10-16 10:00:00'));

        $db  = $this->getDBDriver();
        $ids = array_map('intval', $db->setQuery('SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__frontenderrors') . ' ORDER BY ' . $db->quoteName('id'))->loadColumn());
        $this->assertCount(2, $ids);

        $first  = $this->row($ids[0])->source_extension;
        $second = $this->row($ids[1])->source_extension;
        $this->assertSame(100, mb_strlen($first));
        $this->assertSame(100, mb_strlen($second));
        $this->assertStringStartsWith('external:hhh', $first);
        $this->assertStringStartsWith('com_xxx', $second);
    }

    public function testHugeLineAndColumnAreStored(): void
    {
        $report         = $this->report('far');
        $report['line'] = 99999999999;
        $report['col']  = 99999999999;

        $this->assertSame(1, $this->repo->record([$report], 'visitor', '2026-10-16 10:00:00'));
        $db  = $this->getDBDriver();
        $row = $this->row((int) $db->setQuery('SELECT MAX(' . $db->quoteName('id') . ') FROM ' . $db->quoteName('#__frontenderrors'))->loadResult());
        $this->assertSame(4294967295, (int) $row->line);
        $this->assertSame(4294967295, (int) $row->col);
    }

    public function testSeparateRowsPerDayPageAndOrigin(): void
    {
        foreach (['/a', '/b'] as $page) {
            foreach (['2026-10-15 10:00:00', '2026-10-16 10:00:00'] as $now) {
                foreach (['visitor', 'scan'] as $origin) {
                    $this->repo->record([$this->report('boom', $page)], $origin, $now);
                }
            }
        }

        $this->assertSame(1, $this->rows('#__frontenderrors'));
        $this->assertSame(8, $this->rows('#__frontenderrors_hits'));
    }

    public function testRecordRejectsUnknownOrigin(): void
    {
        $this->assertSame(0, $this->repo->record([$this->report()], 'bogus', '2026-10-16 10:00:00'));
        $this->assertSame(0, $this->rows('#__frontenderrors'));
    }

    public function testSummaryCountsDistinctNotHits(): void
    {
        $now = '2026-10-16 10:00:00';
        $this->repo->record([$this->report('A', '/a', 50)], 'visitor', $now);

        for ($i = 0; $i < 6; $i++) {
            $this->repo->record([$this->report('A', '/a', 50)], 'visitor', $now);
        }

        $this->repo->record([$this->report('B', '/a')], 'visitor', $now);
        $this->repo->record([$this->report('B', '/b')], 'visitor', $now);

        $this->assertSame(
            ['errors' => 2, 'pages' => 2, 'regressed' => false],
            $this->repo->summary('2026-10-10', '2026-10-16')
        );
    }

    public function testTopPagesOrder(): void
    {
        $now = '2026-10-16 10:00:00';
        $this->repo->record([$this->report('e1', '/a', 1), $this->report('e2', '/a', 2)], 'visitor', $now);
        $this->repo->record([$this->report('e3', '/a', 2)], 'visitor', $now);
        $this->repo->record([$this->report('e1', '/b', 50), $this->report('e2', '/b', 1)], 'visitor', $now);
        $this->repo->record([$this->report('e3', '/b', 1)], 'visitor', $now);
        $this->repo->record([$this->report('only', '/c', 50)], 'visitor', $now);

        for ($i = 0; $i < 19; $i++) {
            $this->repo->record([$this->report('only', '/c', 50)], 'visitor', $now);
        }

        $top = $this->repo->topPages('2026-10-16', '2026-10-16', 10);

        $this->assertSame(['/b', '/a', '/c'], array_column($top, 'page'));
        $this->assertSame([3, 3, 1], array_column($top, 'errors'));
        $this->assertSame([52, 5, 1000], array_column($top, 'hits'));
        $this->assertSame('only', $top[2]['top_message']);
        $this->assertSame('e1', $top[0]['top_message']);
        $this->assertSame('2026-10-16', $top[0]['last_seen']);
        $this->assertTrue($top[0]['has_new']);
        $this->assertFalse($top[0]['has_regressed']);

        $this->assertCount(1, $this->repo->topPages('2026-10-16', '2026-10-16', 1));
    }

    public function testLastSeenIsPerPageAndInRange(): void
    {
        $this->repo->record([$this->report('A', '/a')], 'visitor', '2026-10-12 10:00:00');
        $this->repo->record([$this->report('A', '/b')], 'visitor', '2026-10-16 10:00:00');

        $byPage = array_column($this->repo->topPages('2026-10-10', '2026-10-16', 10), 'last_seen', 'page');

        $this->assertSame('2026-10-12', $byPage['/a']);
        $this->assertSame('2026-10-16', $byPage['/b']);

        $inRange = array_column($this->repo->topPages('2026-10-10', '2026-10-13', 10), 'last_seen', 'page');

        $this->assertSame(['/a' => '2026-10-12'], $inRange);
    }

    public function testHasNewFalseForOlderErrors(): void
    {
        $this->repo->record([$this->report('old', '/a')], 'visitor', '2026-10-01 10:00:00');
        $this->repo->record([$this->report('old', '/a')], 'visitor', '2026-10-16 10:00:00');

        $top = $this->repo->topPages('2026-10-10', '2026-10-16', 10);

        $this->assertCount(1, $top);
        $this->assertFalse($top[0]['has_new']);
    }

    public function testRangeExcludesOldHits(): void
    {
        $this->repo->record([$this->report('old', '/a')], 'visitor', '2026-10-01 10:00:00');

        $this->assertSame(0, $this->repo->summary('2026-10-10', '2026-10-16')['errors']);
        $this->assertSame([], $this->repo->topPages('2026-10-10', '2026-10-16', 10));
        $this->assertSame([], $this->repo->pageErrors('/a', '2026-10-10', '2026-10-16'));
        $this->assertSame(1, $this->repo->summary('2026-10-01', '2026-10-16')['errors']);
    }

    public function testIgnoredExcluded(): void
    {
        $this->repo->record([$this->report('boom', '/a')], 'visitor', '2026-10-16 10:00:00');
        $id = $this->id('boom');

        $this->assertTrue($this->repo->setState($id, ErrorRepository::STATE_IGNORED, '2026-10-16 10:30:00'));
        $this->repo->record([$this->report('boom', '/a')], 'visitor', '2026-10-16 11:00:00');

        $this->assertSame(2, (int) $this->getDBDriver()->setQuery('SELECT SUM(' . $this->getDBDriver()->quoteName('hits') . ') FROM ' . $this->getDBDriver()->quoteName('#__frontenderrors_hits'))->loadResult());
        $this->assertSame(2, (int) $this->row($id)->state);
        $this->assertSame(0, $this->repo->summary('2026-10-16', '2026-10-16')['errors']);
        $this->assertSame([], $this->repo->topPages('2026-10-16', '2026-10-16', 10));
        $this->assertSame([], $this->repo->pageErrors('/a', '2026-10-16', '2026-10-16'));
    }

    public function testResolvedThenRegressed(): void
    {
        $this->repo->record([$this->report('boom', '/a')], 'visitor', '2026-10-15 09:00:00');
        $id = $this->id('boom');

        $this->assertTrue($this->repo->setState($id, ErrorRepository::STATE_RESOLVED, '2026-10-16 10:00:00'));
        $this->assertSame(0, $this->repo->summary('2026-10-16', '2026-10-16')['errors']);

        $this->repo->record([$this->report('boom', '/a')], 'visitor', '2026-10-16 20:00:00');
        $this->assertSame(ErrorRepository::STATE_RESOLVED, (int) $this->row($id)->state);
        $this->assertFalse($this->repo->summary('2026-10-16', '2026-10-16')['regressed']);

        $this->repo->record([$this->report('boom', '/a')], 'visitor', '2026-10-17 10:00:00');
        $this->assertSame(ErrorRepository::STATE_RESOLVED, (int) $this->row($id)->state, 'exactly 24h is still inside the grace period');

        $this->repo->record([$this->report('boom', '/a')], 'visitor', '2026-10-17 10:00:01');
        $this->assertSame(ErrorRepository::STATE_REGRESSED, (int) $this->row($id)->state);

        $summary = $this->repo->summary('2026-10-16', '2026-10-17');
        $this->assertTrue($summary['regressed']);
        $this->assertSame(1, $summary['errors']);

        $top = $this->repo->topPages('2026-10-16', '2026-10-17', 10);
        $this->assertTrue($top[0]['has_regressed']);
    }

    public function testSetStateRejectsUnknown(): void
    {
        $this->repo->record([$this->report()], 'visitor', '2026-10-16 10:00:00');
        $id = $this->id('boom');

        $this->assertFalse($this->repo->setState($id, 3, '2026-10-16 10:00:00'));
        $this->assertFalse($this->repo->setState($id, 7, '2026-10-16 10:00:00'));
        $this->assertFalse($this->repo->setState($id + 1000, 1, '2026-10-16 10:00:00'));
        $this->assertSame(0, (int) $this->row($id)->state);
    }

    public function testCapDropsNewFingerprintsOnly(): void
    {
        $repo = new class ($this->getDBDriver()) extends ErrorRepository {
            protected function maxErrors(): int
            {
                return 3;
            }
        };

        $now = '2026-10-16 10:00:00';
        $this->assertSame(1, $repo->record([$this->report('e1')], 'visitor', $now));
        $this->assertSame(1, $repo->record([$this->report('e2')], 'visitor', $now));
        $ignored = $this->seedError(str_repeat('f', 40), ErrorRepository::STATE_IGNORED, $now);
        $this->assertGreaterThan(0, $ignored);

        $this->assertFalse($repo->isSaturated(), 'ignored rows do not count');
        $this->assertSame(1, $repo->record([$this->report('e3')], 'visitor', $now));
        $this->assertTrue($repo->isSaturated());

        $this->assertSame(1, $repo->record([$this->report('e1', '/a', 2)], 'visitor', $now));
        $this->assertSame(0, $repo->record([$this->report('e4')], 'visitor', $now));

        $this->assertSame(0, $this->id('e4'));
        $this->assertSame(4, $this->rows('#__frontenderrors'));
    }

    public function testHitCapDropsNewRowsOnly(): void
    {
        $repo = new class ($this->getDBDriver()) extends ErrorRepository {
            protected function maxHits(): int
            {
                return 1;
            }
        };

        $now = '2026-10-16 10:00:00';
        $this->assertSame(1, $repo->record([$this->report('e1')], 'visitor', $now));
        $this->assertTrue($repo->isSaturated());
        $this->assertSame(1, $repo->record([$this->report('e1')], 'visitor', $now), 'existing hit row still increments');
        $this->assertSame(0, $repo->record([$this->report('e2')], 'visitor', $now));
        $this->assertSame(0, $repo->record([$this->report('e1', '/b')], 'visitor', $now));
        $this->assertSame(1, $this->rows('#__frontenderrors'));
        $this->assertSame(1, $this->rows('#__frontenderrors_hits'));
    }

    public function testPurge(): void
    {
        $old = '2026-09-01 10:00:00';

        $openOld = $this->seedError(str_repeat('a', 40), 0, $old);
        $this->seedHit($openOld, '/a', '2026-09-01');

        $openKept = $this->seedError(str_repeat('b', 40), 0, $old);
        $this->seedHit($openKept, '/a', '2026-10-15');
        $this->seedHit($openKept, '/b', '2026-09-01');

        $resolvedOld    = $this->seedError(str_repeat('c', 40), 1, $old, '2026-09-02 10:00:00');
        $resolvedRecent = $this->seedError(str_repeat('d', 40), 1, $old, '2026-10-15 10:00:00');
        $ignored        = $this->seedError(str_repeat('e', 40), 2, $old, '2026-09-02 10:00:00');
        $fresh          = $this->seedError(str_repeat('f', 40), 0, '2026-10-16 09:00:00');

        $this->repo->purge('2026-10-01', '2026-10-16 10:00:00');

        $db   = $this->getDBDriver();
        $ids  = $db->setQuery('SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__frontenderrors') . ' ORDER BY ' . $db->quoteName('id'))->loadColumn();
        $this->assertSame([$openKept, $resolvedRecent, $ignored, $fresh], array_map('intval', $ids));

        $days = $db->setQuery('SELECT ' . $db->quoteName('day') . ' FROM ' . $db->quoteName('#__frontenderrors_hits'))->loadColumn();
        $this->assertSame(['2026-10-15'], $days);
    }

    public function testConcurrentInsertFallsBackToUpdate(): void
    {
        $report = $this->report('race', '/a', 2);

        // The "other visitor" already inserted the fingerprint.
        $this->seedError($report['fingerprint'], 0, '2026-10-16 09:59:59');

        $repo = new class ($this->getDBDriver()) extends ErrorRepository {
            private bool $first = true;

            protected function findErrorId(string $fingerprint): ?int
            {
                if ($this->first) {
                    $this->first = false;

                    return null;
                }

                return parent::findErrorId($fingerprint);
            }
        };

        $this->assertSame(1, $repo->record([$report], 'visitor', '2026-10-16 10:00:00'));
        $this->assertSame(1, $this->rows('#__frontenderrors'));
        $this->assertSame(1, $this->rows('#__frontenderrors_hits'));

        $row = $this->row($this->id('race'));
        $this->assertSame('2026-10-16 10:00:00', $row->last_seen);

        $db = $this->getDBDriver();
        $this->assertSame(2, (int) $db->setQuery('SELECT SUM(' . $db->quoteName('hits') . ') FROM ' . $db->quoteName('#__frontenderrors_hits'))->loadResult());
    }

    public function testPageErrorsDetails(): void
    {
        $now = '2026-10-16 10:00:00';
        $this->repo->record([$this->report('quiet', '/a', 1)], 'scan', $now);
        $this->repo->record([$this->report('loud', '/a', 5)], 'scan', $now);
        $this->repo->record([$this->report('loud', '/a', 5)], 'visitor', $now);
        $this->repo->record([$this->report('elsewhere', '/b', 5)], 'visitor', $now);

        $errors = $this->repo->pageErrors('/a', '2026-10-16', '2026-10-16');

        $this->assertSame(['loud', 'quiet'], array_column($errors, 'message'));
        $this->assertSame([10, 1], array_column($errors, 'hits'));
        $this->assertSame([['scan', 'visitor'], ['scan']], array_column($errors, 'origins'));
        $this->assertSame('error', $errors[0]['type']);
        $this->assertSame('/media/com_foo/js/a.js', $errors[0]['file']);
        $this->assertSame(3, $errors[0]['line']);
        $this->assertSame(4, $errors[0]['col']);
        $this->assertSame('at x', $errors[0]['stack']);
        $this->assertSame('com_foo', $errors[0]['source_extension']);
        $this->assertSame('2026-10-16 10:00:00', $errors[0]['first_seen']);
        $this->assertSame(0, $errors[0]['state']);
        $this->assertIsInt($errors[0]['id']);
    }

    public function testPageErrorsIncludesResolvedButNotIgnored(): void
    {
        $now = '2026-10-16 10:00:00';
        $this->repo->record([$this->report('r', '/a'), $this->report('i', '/a')], 'visitor', $now);
        $this->repo->setState($this->id('r'), 1, $now);
        $this->repo->setState($this->id('i'), 2, $now);

        $this->assertSame(['r'], array_column($this->repo->pageErrors('/a', '2026-10-16', '2026-10-16'), 'message'));
    }
}
