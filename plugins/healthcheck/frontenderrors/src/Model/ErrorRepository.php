<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Frontenderrors\Model;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;
use Joomla\Plugin\Healthcheck\Frontenderrors\Report\SourceResolver;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Stores frontend JavaScript errors and where they happen.
 *
 * Only portable SQL is used: every value is bound, there is no upsert syntax, and aggregates avoid
 * engine specific features. Dates are passed in by the caller (UTC, `Y-m-d H:i:s` and `Y-m-d`).
 *
 * @since  __DEPLOY_VERSION__
 */
class ErrorRepository
{
    public const STATE_OPEN = 0;

    public const STATE_RESOLVED = 1;

    public const STATE_IGNORED = 2;

    public const STATE_REGRESSED = 3;

    /**
     * Maximum number of tracked (not ignored) fingerprints.
     */
    public const MAX_ERRORS = 1000;

    /**
     * Maximum number of rows in the hits table.
     */
    public const MAX_HITS = 20000;

    /**
     * Seconds after resolving during which a new hit does not count as a regression.
     */
    public const GRACE_SECONDS = 86400;

    private const TABLE_ERRORS = '#__frontenderrors';

    private const TABLE_HITS = '#__frontenderrors_hits';

    /**
     * @var    DatabaseInterface
     * @since  __DEPLOY_VERSION__
     */
    private DatabaseInterface $db;

    /**
     * @param   DatabaseInterface  $db  The database.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Store normalised reports.
     *
     * @param   array[]  $reports  Normalised reports (see ReportNormaliser).
     * @param   string   $origin   `visitor` or `scan`.
     * @param   string   $now      Current UTC time, `Y-m-d H:i:s`.
     *
     * @return  integer  Number of reports stored.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function record(array $reports, string $origin, string $now): int
    {
        if (!\in_array($origin, ['visitor', 'scan'], true)) {
            return 0;
        }

        $day    = substr($now, 0, 10);
        $stored = 0;

        foreach ($reports as $report) {
            if ($this->recordOne($report, $origin, $now, $day)) {
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * Counts of open errors (state open or regressed) with hits in the range.
     *
     * @param   string  $fromDay  First day, `Y-m-d`.
     * @param   string  $toDay    Last day, `Y-m-d`.
     *
     * @return  array{errors:int, pages:int, regressed:bool}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function summary(string $fromDay, string $toDay): array
    {
        $db         = $this->db;
        $open       = self::STATE_OPEN;
        $regressed  = self::STATE_REGRESSED;
        $query      = $db->createQuery()
            ->select(
                [
                    'COUNT(DISTINCT ' . $db->quoteName('e.id') . ') AS ' . $db->quoteName('n_errors'),
                    'COUNT(DISTINCT ' . $db->quoteName('h.page_hash') . ') AS ' . $db->quoteName('n_pages'),
                    'COUNT(DISTINCT CASE WHEN ' . $db->quoteName('e.state') . ' = :regressed THEN ' . $db->quoteName('e.id') . ' END) AS ' . $db->quoteName('n_regressed'),
                ]
            )
            ->from($db->quoteName(self::TABLE_HITS, 'h'))
            ->join('INNER', $db->quoteName(self::TABLE_ERRORS, 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('h.error_id'))
            ->where($db->quoteName('e.state') . ' IN (:open, :regressed2)')
            ->where($db->quoteName('h.day') . ' >= :fromDay')
            ->where($db->quoteName('h.day') . ' <= :toDay')
            ->bind(':regressed', $regressed, ParameterType::INTEGER)
            ->bind(':open', $open, ParameterType::INTEGER)
            ->bind(':regressed2', $regressed, ParameterType::INTEGER)
            ->bind(':fromDay', $fromDay)
            ->bind(':toDay', $toDay);

        $row = $db->setQuery($query)->loadObject();

        return [
            'errors'    => (int) ($row->n_errors ?? 0),
            'pages'     => (int) ($row->n_pages ?? 0),
            'regressed' => (int) ($row->n_regressed ?? 0) > 0,
        ];
    }

    /**
     * The pages with the most open errors.
     *
     * @param   string   $fromDay  First day, `Y-m-d`.
     * @param   string   $toDay    Last day, `Y-m-d`.
     * @param   integer  $limit    Maximum number of pages.
     *
     * @return  array[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public function topPages(string $fromDay, string $toDay, int $limit): array
    {
        $db         = $this->db;
        $open       = self::STATE_OPEN;
        $regressed  = self::STATE_REGRESSED;
        $fromStart  = $fromDay . ' 00:00:00';
        $limit      = max(1, $limit);
        $query      = $db->createQuery()
            ->select(
                [
                    $db->quoteName('h.page_hash', 'page_hash'),
                    $db->quoteName('h.page', 'page'),
                    'COUNT(DISTINCT ' . $db->quoteName('e.id') . ') AS ' . $db->quoteName('n_errors'),
                    'SUM(' . $db->quoteName('h.hits') . ') AS ' . $db->quoteName('n_hits'),
                    'MAX(' . $db->quoteName('h.day') . ') AS ' . $db->quoteName('last_day'),
                    'MAX(CASE WHEN ' . $db->quoteName('e.first_seen') . ' >= :fromStart THEN 1 ELSE 0 END) AS ' . $db->quoteName('is_new'),
                    'MAX(CASE WHEN ' . $db->quoteName('e.state') . ' = :regressed THEN 1 ELSE 0 END) AS ' . $db->quoteName('is_regressed'),
                ]
            )
            ->from($db->quoteName(self::TABLE_HITS, 'h'))
            ->join('INNER', $db->quoteName(self::TABLE_ERRORS, 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('h.error_id'))
            ->where($db->quoteName('e.state') . ' IN (:open, :regressed2)')
            ->where($db->quoteName('h.day') . ' >= :fromDay')
            ->where($db->quoteName('h.day') . ' <= :toDay')
            ->group([$db->quoteName('h.page_hash'), $db->quoteName('h.page')])
            ->order([$db->quoteName('n_errors') . ' DESC', $db->quoteName('n_hits') . ' DESC', $db->quoteName('last_day') . ' DESC'])
            ->bind(':fromStart', $fromStart)
            ->bind(':regressed', $regressed, ParameterType::INTEGER)
            ->bind(':open', $open, ParameterType::INTEGER)
            ->bind(':regressed2', $regressed, ParameterType::INTEGER)
            ->bind(':fromDay', $fromDay)
            ->bind(':toDay', $toDay);

        $rows = $db->setQuery($query, 0, $limit)->loadObjectList();
        $out  = [];

        foreach ($rows as $row) {
            $out[] = [
                'page'          => (string) $row->page,
                'errors'        => (int) $row->n_errors,
                'hits'          => (int) $row->n_hits,
                'top_message'   => $this->topMessage((string) $row->page_hash, $fromDay, $toDay),
                'last_seen'     => (string) $row->last_day,
                'has_new'       => (int) $row->is_new > 0,
                'has_regressed' => (int) $row->is_regressed > 0,
            ];
        }

        return $out;
    }

    /**
     * The errors of one page, busiest first. Ignored errors are left out.
     *
     * @param   string  $page     Page path.
     * @param   string  $fromDay  First day, `Y-m-d`.
     * @param   string  $toDay    Last day, `Y-m-d`.
     *
     * @return  array[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public function pageErrors(string $page, string $fromDay, string $toDay): array
    {
        $db      = $this->db;
        $hash    = sha1($page);
        $ignored = self::STATE_IGNORED;
        $query   = $db->createQuery()
            ->select(
                [
                    $db->quoteName('e.id'),
                    $db->quoteName('e.type'),
                    $db->quoteName('e.message'),
                    $db->quoteName('e.file'),
                    $db->quoteName('e.line'),
                    $db->quoteName('e.col'),
                    $db->quoteName('e.stack'),
                    $db->quoteName('e.source_extension'),
                    $db->quoteName('e.first_seen'),
                    $db->quoteName('e.last_seen'),
                    $db->quoteName('e.state'),
                    'SUM(' . $db->quoteName('h.hits') . ') AS ' . $db->quoteName('n_hits'),
                ]
            )
            ->from($db->quoteName(self::TABLE_HITS, 'h'))
            ->join('INNER', $db->quoteName(self::TABLE_ERRORS, 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('h.error_id'))
            ->where($db->quoteName('h.page_hash') . ' = :hash')
            ->where($db->quoteName('e.state') . ' <> :ignored')
            ->where($db->quoteName('h.day') . ' >= :fromDay')
            ->where($db->quoteName('h.day') . ' <= :toDay')
            ->group(
                [
                    $db->quoteName('e.id'),
                    $db->quoteName('e.type'),
                    $db->quoteName('e.message'),
                    $db->quoteName('e.file'),
                    $db->quoteName('e.line'),
                    $db->quoteName('e.col'),
                    $db->quoteName('e.stack'),
                    $db->quoteName('e.source_extension'),
                    $db->quoteName('e.first_seen'),
                    $db->quoteName('e.last_seen'),
                    $db->quoteName('e.state'),
                ]
            )
            ->order([$db->quoteName('n_hits') . ' DESC', $db->quoteName('e.id') . ' ASC'])
            ->bind(':hash', $hash)
            ->bind(':ignored', $ignored, ParameterType::INTEGER)
            ->bind(':fromDay', $fromDay)
            ->bind(':toDay', $toDay);

        $rows = $db->setQuery($query)->loadObjectList();

        if (!$rows) {
            return [];
        }

        $ids     = array_map(static fn ($row): int => (int) $row->id, $rows);
        $origins = $this->origins($ids, $hash, $fromDay, $toDay);
        $out     = [];

        foreach ($rows as $row) {
            $out[] = [
                'id'               => (int) $row->id,
                'type'             => (string) $row->type,
                'message'          => (string) $row->message,
                'file'             => (string) $row->file,
                'line'             => (int) $row->line,
                'col'              => (int) $row->col,
                'stack'            => (string) $row->stack,
                'source_extension' => $row->source_extension === null ? null : (string) $row->source_extension,
                'hits'             => (int) $row->n_hits,
                'first_seen'       => (string) $row->first_seen,
                'last_seen'        => (string) $row->last_seen,
                'state'            => (int) $row->state,
                'origins'          => $origins[(int) $row->id] ?? [],
            ];
        }

        return $out;
    }

    /**
     * Change the state of an error by hand.
     *
     * @param   integer  $id     Error id.
     * @param   integer  $state  STATE_OPEN, STATE_RESOLVED or STATE_IGNORED.
     * @param   string   $now    Current UTC time, `Y-m-d H:i:s`.
     *
     * @return  boolean  False for an unknown state or error.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function setState(int $id, int $state, string $now): bool
    {
        if (!\in_array($state, [self::STATE_OPEN, self::STATE_RESOLVED, self::STATE_IGNORED], true)) {
            return false;
        }

        $db    = $this->db;
        $check = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName(self::TABLE_ERRORS))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        if ($db->setQuery($check)->loadResult() === null) {
            return false;
        }

        $query = $db->createQuery()
            ->update($db->quoteName(self::TABLE_ERRORS))
            ->set($db->quoteName('state') . ' = :state')
            ->set($db->quoteName('state_changed') . ' = :now')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':state', $state, ParameterType::INTEGER)
            ->bind(':now', $now)
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($query)->execute();

        return true;
    }

    /**
     * Delete old hits and the errors that no longer have any.
     *
     * Ignored errors are kept so that they stay ignored. Errors created within the grace period are
     * kept so that a purge cannot delete a fingerprint whose first hit is still being written.
     *
     * @param   string  $cutoffDay  Hits before this day are deleted, `Y-m-d`.
     * @param   string  $now        Current UTC time, `Y-m-d H:i:s`.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function purge(string $cutoffDay, string $now): void
    {
        $db = $this->db;

        $query = $db->createQuery()
            ->delete($db->quoteName(self::TABLE_HITS))
            ->where($db->quoteName('day') . ' < :cutoff')
            ->bind(':cutoff', $cutoffDay);
        $db->setQuery($query)->execute();

        $cutoffStart = $cutoffDay . ' 00:00:00';
        $recent      = $this->shift($now, -self::GRACE_SECONDS);
        $open        = self::STATE_OPEN;
        $regressed   = self::STATE_REGRESSED;
        $resolved    = self::STATE_RESOLVED;
        $noHits      = $db->quoteName('id') . ' NOT IN (SELECT ' . $db->quoteName('error_id') . ' FROM ' . $db->quoteName(self::TABLE_HITS) . ')';

        $query = $db->createQuery()
            ->delete($db->quoteName(self::TABLE_ERRORS))
            ->where($noHits)
            ->where($db->quoteName('first_seen') . ' < :recent')
            ->where(
                '(' . $db->quoteName('state') . ' IN (:open, :regressed)'
                . ' OR (' . $db->quoteName('state') . ' = :resolved AND ' . $db->quoteName('state_changed') . ' < :cutoff))'
            )
            ->bind(':recent', $recent)
            ->bind(':open', $open, ParameterType::INTEGER)
            ->bind(':regressed', $regressed, ParameterType::INTEGER)
            ->bind(':resolved', $resolved, ParameterType::INTEGER)
            ->bind(':cutoff', $cutoffStart);
        $db->setQuery($query)->execute();
    }

    /**
     * Whether the storage limits stop new errors being recorded.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function isSaturated(): bool
    {
        return $this->errorCount() >= $this->maxErrors() || $this->hitCount() >= $this->maxHits();
    }

    /**
     * Find an error by fingerprint.
     *
     * @param   string  $fingerprint  The fingerprint.
     *
     * @return  integer|null
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function findErrorId(string $fingerprint): ?int
    {
        $db    = $this->db;
        $query = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName(self::TABLE_ERRORS))
            ->where($db->quoteName('fingerprint') . ' = :fp')
            ->bind(':fp', $fingerprint);

        $id = $db->setQuery($query)->loadResult();

        return $id === null ? null : (int) $id;
    }

    /**
     * Cap on tracked fingerprints.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function maxErrors(): int
    {
        return self::MAX_ERRORS;
    }

    /**
     * Cap on hit rows.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    protected function maxHits(): int
    {
        return self::MAX_HITS;
    }

    /**
     * Store one report.
     *
     * @param   array    $report  Normalised report.
     * @param   string   $origin  `visitor` or `scan`.
     * @param   string   $now     Current UTC time.
     * @param   string   $day     The day part of `$now`.
     *
     * @return  boolean  True if the report was stored.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function recordOne(array $report, string $origin, string $now, string $day): bool
    {
        $count = max(1, (int) ($report['count'] ?? 1));
        $id    = $this->findErrorId((string) $report['fingerprint']);

        if ($id === null) {
            // A new fingerprint always needs a new hit row as well, so both caps apply.
            if ($this->errorCount() >= $this->maxErrors() || $this->hitCount() >= $this->maxHits()) {
                return false;
            }

            try {
                $id = $this->insertError($report, $now);
            } catch (ExecutionFailureException $e) {
                // A concurrent request inserted the same fingerprint first: use that row.
                $id = $this->findErrorId((string) $report['fingerprint']);

                if ($id === null) {
                    throw $e;
                }
            }
        }

        $this->touchError($id, $now);

        return $this->addHits($id, (string) $report['page'], $day, $origin, $count);
    }

    /**
     * @param   array   $report  Normalised report.
     * @param   string  $now     Current UTC time.
     *
     * @return  integer  The new id.
     *
     * @throws  ExecutionFailureException  On a duplicate fingerprint.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function insertError(array $report, string $now): int
    {
        $db        = $this->db;
        $file      = (string) $report['file'];
        $source    = SourceResolver::resolve($file);
        $source    = $source === null ? null : mb_substr($source, 0, 100, 'UTF-8');
        $finger    = (string) $report['fingerprint'];
        $type      = (string) $report['type'];
        $message   = (string) $report['message'];
        $line      = max(0, min(4294967295, (int) $report['line']));
        $col       = max(0, min(4294967295, (int) $report['col']));
        $stack     = (string) $report['stack'];
        $state     = self::STATE_OPEN;
        $query     = $db->createQuery()
            ->insert($db->quoteName(self::TABLE_ERRORS))
            ->columns(
                $db->quoteName(['fingerprint', 'type', 'message', 'file', 'line', 'col', 'stack', 'source_extension', 'first_seen', 'last_seen', 'state'])
            )
            ->values(':fp, :type, :message, :file, :line, :col, :stack, :source, :first, :last, :state')
            ->bind(':fp', $finger)
            ->bind(':type', $type)
            ->bind(':message', $message)
            ->bind(':file', $file)
            ->bind(':line', $line, ParameterType::INTEGER)
            ->bind(':col', $col, ParameterType::INTEGER)
            ->bind(':stack', $stack)
            ->bind(':source', $source, $source === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':first', $now)
            ->bind(':last', $now)
            ->bind(':state', $state, ParameterType::INTEGER);

        $db->setQuery($query)->execute();

        return (int) $db->insertid();
    }

    /**
     * Update `last_seen` and turn a resolved error that outlived its grace period into a regression.
     *
     * @param   integer  $id   Error id.
     * @param   string   $now  Current UTC time.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function touchError(int $id, string $now): void
    {
        $db        = $this->db;
        $resolved  = self::STATE_RESOLVED;
        $regressed = self::STATE_REGRESSED;
        $threshold = $this->shift($now, -self::GRACE_SECONDS);

        $query = $db->createQuery()
            ->update($db->quoteName(self::TABLE_ERRORS))
            ->set($db->quoteName('state') . ' = :regressed')
            ->set($db->quoteName('state_changed') . ' = :now')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('state') . ' = :resolved')
            ->where($db->quoteName('state_changed') . ' < :threshold')
            ->bind(':regressed', $regressed, ParameterType::INTEGER)
            ->bind(':now', $now)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':resolved', $resolved, ParameterType::INTEGER)
            ->bind(':threshold', $threshold);
        $db->setQuery($query)->execute();

        $query = $db->createQuery()
            ->update($db->quoteName(self::TABLE_ERRORS))
            ->set($db->quoteName('last_seen') . ' = :now')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':now', $now)
            ->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    /**
     * Add to the hit row of an error, page, day and origin, creating it if needed.
     *
     * @param   integer  $id      Error id.
     * @param   string   $page    Page path.
     * @param   string   $day     Day, `Y-m-d`.
     * @param   string   $origin  `visitor` or `scan`.
     * @param   integer  $count   Number of hits.
     *
     * @return  boolean  False if the hit table is full and the row would be new.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function addHits(int $id, string $page, string $day, string $origin, int $count): bool
    {
        if ($this->incrementHits($id, $page, $day, $origin, $count)) {
            return true;
        }

        if ($this->hitCount() >= $this->maxHits()) {
            return false;
        }

        $db    = $this->db;
        $hash  = sha1($page);
        $query = $db->createQuery()
            ->insert($db->quoteName(self::TABLE_HITS))
            ->columns($db->quoteName(['error_id', 'page', 'page_hash', 'day', 'origin', 'hits']))
            ->values(':id, :page, :hash, :day, :origin, :count')
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':page', $page)
            ->bind(':hash', $hash)
            ->bind(':day', $day)
            ->bind(':origin', $origin)
            ->bind(':count', $count, ParameterType::INTEGER);

        try {
            $db->setQuery($query)->execute();
        } catch (ExecutionFailureException $e) {
            // A concurrent request created the row first.
            if (!$this->incrementHits($id, $page, $day, $origin, $count)) {
                throw $e;
            }
        }

        return true;
    }

    /**
     * @param   integer  $id      Error id.
     * @param   string   $page    Page path.
     * @param   string   $day     Day, `Y-m-d`.
     * @param   string   $origin  `visitor` or `scan`.
     * @param   integer  $count   Number of hits.
     *
     * @return  boolean  True if a row existed.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function incrementHits(int $id, string $page, string $day, string $origin, int $count): bool
    {
        $db    = $this->db;
        $hash  = sha1($page);
        $query = $db->createQuery()
            ->update($db->quoteName(self::TABLE_HITS))
            ->set($db->quoteName('hits') . ' = ' . $db->quoteName('hits') . ' + :count')
            ->where($db->quoteName('error_id') . ' = :id')
            ->where($db->quoteName('page_hash') . ' = :hash')
            ->where($db->quoteName('day') . ' = :day')
            ->where($db->quoteName('origin') . ' = :origin')
            ->bind(':count', $count, ParameterType::INTEGER)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':hash', $hash)
            ->bind(':day', $day)
            ->bind(':origin', $origin);
        $db->setQuery($query)->execute();

        return $db->getAffectedRows() > 0;
    }

    /**
     * @return  integer  Number of tracked (not ignored) errors.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function errorCount(): int
    {
        $db      = $this->db;
        $ignored = self::STATE_IGNORED;
        $query   = $db->createQuery()
            ->select('COUNT(*)')
            ->from($db->quoteName(self::TABLE_ERRORS))
            ->where($db->quoteName('state') . ' <> :ignored')
            ->bind(':ignored', $ignored, ParameterType::INTEGER);

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * @return  integer  Number of hit rows.
     *
     * @since   __DEPLOY_VERSION__
     */
    private function hitCount(): int
    {
        $db    = $this->db;
        $query = $db->createQuery()
            ->select('COUNT(*)')
            ->from($db->quoteName(self::TABLE_HITS));

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * The message of the open error with the most hits on a page.
     *
     * @param   string  $hash     Page hash.
     * @param   string  $fromDay  First day.
     * @param   string  $toDay    Last day.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function topMessage(string $hash, string $fromDay, string $toDay): string
    {
        $db        = $this->db;
        $open      = self::STATE_OPEN;
        $regressed = self::STATE_REGRESSED;
        $query     = $db->createQuery()
            ->select($db->quoteName('e.message'))
            ->from($db->quoteName(self::TABLE_HITS, 'h'))
            ->join('INNER', $db->quoteName(self::TABLE_ERRORS, 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('h.error_id'))
            ->where($db->quoteName('h.page_hash') . ' = :hash')
            ->where($db->quoteName('e.state') . ' IN (:open, :regressed)')
            ->where($db->quoteName('h.day') . ' >= :fromDay')
            ->where($db->quoteName('h.day') . ' <= :toDay')
            ->group([$db->quoteName('e.id'), $db->quoteName('e.message')])
            ->order('SUM(' . $db->quoteName('h.hits') . ') DESC, ' . $db->quoteName('e.id') . ' ASC')
            ->bind(':hash', $hash)
            ->bind(':open', $open, ParameterType::INTEGER)
            ->bind(':regressed', $regressed, ParameterType::INTEGER)
            ->bind(':fromDay', $fromDay)
            ->bind(':toDay', $toDay);

        return (string) $db->setQuery($query, 0, 1)->loadResult();
    }

    /**
     * Distinct origins per error on one page.
     *
     * @param   int[]   $ids      Error ids.
     * @param   string  $hash     Page hash.
     * @param   string  $fromDay  First day.
     * @param   string  $toDay    Last day.
     *
     * @return  array<int, string[]>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function origins(array $ids, string $hash, string $fromDay, string $toDay): array
    {
        $db    = $this->db;
        $query = $db->createQuery()
            ->select([$db->quoteName('error_id'), $db->quoteName('origin')])
            ->from($db->quoteName(self::TABLE_HITS))
            ->whereIn($db->quoteName('error_id'), $ids)
            ->where($db->quoteName('page_hash') . ' = :hash')
            ->where($db->quoteName('day') . ' >= :fromDay')
            ->where($db->quoteName('day') . ' <= :toDay')
            ->group([$db->quoteName('error_id'), $db->quoteName('origin')])
            ->order($db->quoteName('origin') . ' ASC')
            ->bind(':hash', $hash)
            ->bind(':fromDay', $fromDay)
            ->bind(':toDay', $toDay);

        $out = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $out[(int) $row->error_id][] = (string) $row->origin;
        }

        return $out;
    }

    /**
     * @param   string   $time     `Y-m-d H:i:s`.
     * @param   integer  $seconds  Seconds to add (may be negative).
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function shift(string $time, int $seconds): string
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('UTC')))
            ->modify(($seconds < 0 ? '-' : '+') . abs($seconds) . ' seconds')
            ->format('Y-m-d H:i:s');
    }
}
