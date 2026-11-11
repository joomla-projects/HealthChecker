<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Scan;

use Joomla\CMS\Log\Log;
use Joomla\Plugin\Healthcheck\Database\Catalog\Catalog;
use Joomla\Plugin\Healthcheck\Database\Catalog\MissingObjectException;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreList;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Finds zero dates table by table within a time budget, keeping a merged view of the latest result per table.
 *
 * The state holds arrays and scalars only, so it can be cached as is.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ZeroDateScanner
{
    /**
     * Constructor
     *
     * @param   Catalog   $catalog  The catalog
     * @param   \Closure  $clock    Returns the current time in float seconds
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly Catalog $catalog, private readonly \Closure $clock)
    {
    }

    /**
     * The state before the first pass.
     *
     * `inprogress` is the table being counted right now ({key, at}, `at` from the scanner's clock) or null,
     * `skipped` the tables not counted (key => {name, at, reason}: `at` Y-m-d H:i:s UTC, or null for an entry
     * cached by an older version; `reason` `large` when a request died on it, `name` when its name or a date
     * column name cannot be put in a query, see start()), and `error` the table the last run could not check ({table, at}) or null.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function emptyState(): array
    {
        return [
            'pass'       => null,
            'cursor'     => 0,
            'tables'     => [],
            'results'    => [],
            'complete'   => false,
            'updated'    => null,
            'inprogress' => null,
            'skipped'    => [],
            'error'      => null,
        ];
    }

    /**
     * Seconds after which an in-progress marker means its request died: the longest a request may run
     * (the time budget or PHP's time limit, 300 when that is unlimited) plus a margin.
     *
     * This is a heuristic: on Linux max_execution_time does not count time spent waiting on the database, so
     * a very long COUNT can be taken for dead while it is still running. That table then really is too large
     * to check in one request.
     *
     * @param   float    $budgetSeconds     Seconds a run may take
     * @param   integer  $maxExecutionTime  PHP's max_execution_time, 0 for unlimited
     *
     * @return  float
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function deadAfter(float $budgetSeconds, int $maxExecutionTime): float
    {
        return (float) max($budgetSeconds, $maxExecutionTime > 0 ? $maxExecutionTime : 300) + 30.0;
    }

    /**
     * Whether another request is counting the cursor table right now: its marker is younger than $deadAfter.
     *
     * @param   array  $state      The state
     * @param   float  $now        Current time from the scanner's clock
     * @param   float  $deadAfter  Seconds after which a marker means its request died
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function isBusy(array $state, float $now, float $deadAfter): bool
    {
        $marker = self::marker($state);

        return $marker !== null && $marker['key'] === self::cursorKey($state) && $now - $marker['at'] < $deadAfter;
    }

    /**
     * Begin a new pass. Earlier results are kept until their tables are checked again. Tables skipped as too
     * large within the last $refreshHours stay skipped, so Check now does not die on them again; older ones
     * are tried again. A dead marker left by the old pass (callers check isBusy() first) is recorded in
     * `skipped` before it is cleared, so a restart right after a request died does not count that table again.
     *
     * A table whose name or a date column name holds `#__` or a colon followed by a letter, digit or
     * underscore is skipped for its name (and its old result dropped): the database driver replaces `#__` with
     * the site prefix, and the mysqli statement turns `:name` into a `?` placeholder, anywhere outside string
     * literals, even inside a quoted identifier. The count would read another table or fail on every pass.
     * These entries are rebuilt on every start.
     *
     * @param   array    $state         The state
     * @param   string   $tablePrefix   The site's table prefix
     * @param   string   $now           Current time (Y-m-d H:i:s, UTC)
     * @param   integer  $refreshHours  Hours a result stays fresh
     * @param   float    $deadAfter     Seconds after which a marker means its request died
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function start(array $state, string $tablePrefix, string $now, int $refreshHours, float $deadAfter): array
    {
        // Named from the old pass, which the new one replaces below.
        $dead     = $this->deadKey($state, $deadAfter);
        $deadName = $dead === null ? null : (string) ($state['tables'][$dead]['name'] ?? $dead);
        $tables   = [];
        $byName   = [];

        foreach ($this->catalog->dateColumns($tablePrefix) as $column) {
            $key = strtolower($column['table']);

            $tables[$key]['name']      = $tables[$key]['name'] ?? $column['table'];
            $tables[$key]['columns'][] = ['column' => $column['column'], 'type' => $column['type']];

            if (self::unqueryable($column['table']) || self::unqueryable($column['column'])) {
                $byName[$key] = true;
            }
        }

        ksort($tables);

        $state = $state + self::emptyState();

        $state['pass']       = $now;
        $state['cursor']     = 0;
        $state['tables']     = $tables;
        $state['complete']   = false;
        $state['inprogress'] = null;
        $state['skipped']    = array_filter(
            self::skipped($state),
            static fn (array $entry): bool => $entry['reason'] === 'large' && !ResultStore::isStale($entry['at'], $refreshHours, $now)
        );
        $state['error']      = null;

        if ($dead !== null) {
            // The request that counted this table died: too large to check in one request.
            $state['skipped'][$dead] = ['name' => $deadName, 'at' => $now, 'reason' => 'large'];
        }

        foreach (array_keys($byName) as $key) {
            $state['skipped'][$key] = ['name' => $tables[$key]['name'], 'at' => $now, 'reason' => 'name'];
            unset($state['results'][$key]);
        }

        ksort($state['skipped']);

        return $state;
    }

    /**
     * Check tables from the cursor until the budget is used up, always at least one.
     *
     * Before each table it is marked in `inprogress` with the clock time, and the state is handed to
     * $beforeTable, so the caller can store it. A marker on the cursor table older than $deadAfter means the
     * request counting it died: the table is recorded in `skipped` and the pass moves on instead of dying on
     * it again. A younger marker means another request is counting it now: the state is returned unchanged
     * (callers check isBusy() first). Tables in `skipped` are passed over.
     *
     * A table that is gone is dropped from the results. Any other failure keeps the table's previous result,
     * records `error`, leaves the cursor on that table and ends the run, so a pass never completes through
     * errors.
     *
     * @param   array          $state          The state
     * @param   string         $tablePrefix    The site's table prefix
     * @param   float          $budgetSeconds  Seconds this run may take
     * @param   string         $now            Current time (Y-m-d H:i:s, UTC)
     * @param   integer        $refreshHours   Hours a result stays fresh, for a pass started here
     * @param   float          $deadAfter      Seconds after which a marker means its request died
     * @param   \Closure|null  $beforeTable    Called with the state before each table is counted
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public function run(
        array $state,
        string $tablePrefix,
        float $budgetSeconds,
        string $now,
        int $refreshHours,
        float $deadAfter,
        ?\Closure $beforeTable = null
    ): array {
        if (($state['pass'] ?? null) === null) {
            $state = $this->start($state, $tablePrefix, $now, $refreshHours, $deadAfter);
        }

        $state += self::emptyState();

        if (self::isBusy($state, ($this->clock)(), $deadAfter)) {
            return $state;
        }

        // A marker on the cursor table is dead; any other marker (or an older shape) is left over.
        $dead                = $this->deadKey($state, $deadAfter) !== null;
        $state['skipped']    = self::skipped($state);
        $keys                = array_keys($state['tables']);
        $total               = \count($keys);
        $state['inprogress'] = null;
        $began               = ($this->clock)();

        while ($state['cursor'] < $total) {
            $key   = $keys[$state['cursor']];
            $table = $state['tables'][$key];

            if ($dead) {
                // The request that counted this table died: too large to check in one request.
                $dead                   = false;
                $state['skipped'][$key] = ['name' => $table['name'], 'at' => $now, 'reason' => 'large'];
                $state['cursor']++;

                continue;
            }

            if (isset($state['skipped'][$key])) {
                // Skipped for its name, or as too large recently: not tried again before the refresh hours are up.
                $state['cursor']++;

                continue;
            }

            $state['inprogress'] = ['key' => (string) $key, 'at' => (float) ($this->clock)()];

            if ($beforeTable !== null) {
                $beforeTable($state);
            }

            try {
                $counts  = $this->catalog->zeroDateCounts($table['name'], array_column($table['columns'], 'column'));
                $failure = null;
            } catch (MissingObjectException $e) {
                // Dropped or renamed since the pass started, unless the catalog still lists it (a name the query
                // could not reach is a failure, not a reason to drop the table's result).
                $counts  = null;
                $failure = $this->stillExists($table) ? $e : null;
            } catch (\Throwable $e) {
                $failure = $e;
            }

            if ($failure !== null) {
                Log::add('Database Health Check: could not check ' . $table['name'] . ': ' . $failure->getMessage(), Log::WARNING, 'healthcheck');

                $state['inprogress'] = null;
                $state['error']      = ['table' => $table['name'], 'at' => $now];

                break;
            }

            $columns = [];

            foreach ($counts === null ? [] : $table['columns'] as $column) {
                $zero = (int) ($counts[$column['column']] ?? 0);

                if ($zero > 0) {
                    $columns[] = ['column' => $column['column'], 'type' => $column['type'], 'zero' => $zero];
                }
            }

            if ($columns === []) {
                unset($state['results'][$key]);
            } else {
                $state['results'][$key] = ['table' => $table['name'], 'checked' => $now, 'columns' => $columns];
            }

            unset($state['skipped'][$key]);
            $state['inprogress'] = null;
            $state['error']      = null;
            $state['cursor']++;

            if (($this->clock)() - $began >= $budgetSeconds) {
                break;
            }
        }

        if ($state['cursor'] >= $total && $state['error'] === null) {
            $state['complete'] = true;
            $state['updated']  = $now;
            $state['results']  = array_intersect_key($state['results'], $state['tables']);
            $state['skipped']  = array_intersect_key($state['skipped'], $state['tables']);
        }

        return $state;
    }

    /**
     * Open and ignored zero-date columns, by table then column order.
     *
     * @param   array       $state    The state
     * @param   IgnoreList  $ignored  Ignore entries
     *
     * @return  list<array{table:string, column:string, type:string, zero:int, ignored:bool}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function findings(array $state, IgnoreList $ignored): array
    {
        $results = $state['results'] ?? [];
        ksort($results);

        $findings = [];

        foreach ($results as $result) {
            foreach ($result['columns'] as $column) {
                $findings[] = [
                    'table'   => $result['table'],
                    'column'  => $column['column'],
                    'type'    => $column['type'],
                    'zero'    => (int) $column['zero'],
                    'ignored' => $ignored->isColumnIgnored($result['table'], $column['column']),
                ];
            }
        }

        return $findings;
    }

    /**
     * Number of zero-date columns that are not ignored.
     *
     * @param   array       $state    The state
     * @param   IgnoreList  $ignored  Ignore entries
     *
     * @return  integer  Columns not ignored
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function openCount(array $state, IgnoreList $ignored): int
    {
        return \count(array_filter(self::findings($state, $ignored), static fn ($f) => !$f['ignored']));
    }

    /**
     * Number of zero-date columns that are ignored.
     *
     * @param   array       $state    The state
     * @param   IgnoreList  $ignored  Ignore entries
     *
     * @return  integer  Columns ignored
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function ignoredCount(array $state, IgnoreList $ignored): int
    {
        return \count(array_filter(self::findings($state, $ignored), static fn ($f) => $f['ignored']));
    }

    /**
     * How far the current pass is: tables checked (cursor) and tables in the pass.
     *
     * @param   array  $state  The state
     *
     * @return  array{checked:int, total:int}
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function progress(array $state): array
    {
        return ['checked' => (int) ($state['cursor'] ?? 0), 'total' => \count($state['tables'] ?? [])];
    }
    /**
     * The in-progress marker, or null when there is none or it has an older shape.
     *
     * @param   array  $state  The state
     *
     * @return  array{key:string, at:float}|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function marker(array $state): ?array
    {
        $marker = $state['inprogress'] ?? null;

        if (!\is_array($marker) || !isset($marker['key'], $marker['at']) || !is_numeric($marker['at'])) {
            return null;
        }

        return ['key' => (string) $marker['key'], 'at' => (float) $marker['at']];
    }

    /**
     * Whether a name cannot be put in a query as an identifier: the driver would rewrite its `#__` or a
     * `:placeholder` in it.
     *
     * @param   string  $name  A table or column name
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function unqueryable(string $name): bool
    {
        return str_contains($name, '#__') || preg_match('/:[A-Za-z0-9_]/', $name) === 1;
    }

    /**
     * Whether the table and its date columns are still there, after a count failed as if they were gone.
     * When the catalog cannot tell, they count as there: the failure is then recorded, not hidden.
     *
     * @param   array  $table  The table of the pass ({name, columns})
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function stillExists(array $table): bool
    {
        try {
            return $this->catalog->tableExists($table['name'], array_column($table['columns'], 'column'));
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Key of the cursor table when its in-progress marker is dead (older than $deadAfter), otherwise null.
     *
     * @param   array  $state      The state
     * @param   float  $deadAfter  Seconds after which a marker means its request died
     *
     * @return  ?string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function deadKey(array $state, float $deadAfter): ?string
    {
        $marker = self::marker($state);

        if ($marker === null || $marker['key'] !== self::cursorKey($state) || self::isBusy($state, ($this->clock)(), $deadAfter)) {
            return null;
        }

        return $marker['key'];
    }

    /**
     * Key of the table at the cursor, or null when the pass is through.
     *
     * @param   array  $state  The state
     *
     * @return  ?string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function cursorKey(array $state): ?string
    {
        $key = array_keys($state['tables'] ?? [])[(int) ($state['cursor'] ?? 0)] ?? null;

        return $key === null ? null : (string) $key;
    }

    /**
     * The skipped tables as key => {name, at, reason}; a bare name cached by an older version gets no time, an
     * entry without a reason is `large`.
     *
     * @param   array  $state  The state
     *
     * @return  array<string, array{name:string, at:?string, reason:string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function skipped(array $state): array
    {
        $skipped = [];

        foreach ((array) ($state['skipped'] ?? []) as $key => $entry) {
            $skipped[$key] = \is_array($entry)
                ? [
                    'name'   => (string) ($entry['name'] ?? $key),
                    'at'     => isset($entry['at']) ? (string) $entry['at'] : null,
                    'reason' => ($entry['reason'] ?? null) === 'name' ? 'name' : 'large',
                ]
                : ['name' => (string) $entry, 'at' => null, 'reason' => 'large'];
        }

        return $skipped;
    }
}
