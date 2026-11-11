<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Extension;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Input\Input;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;
use Joomla\Plugin\Healthcheck\Database\Catalog\Catalog;
use Joomla\Plugin\Healthcheck\Database\Catalog\CatalogFactory;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreList;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreRepository;
use Joomla\Plugin\Healthcheck\Database\Ownership\TableOwnership;
use Joomla\Plugin\Healthcheck\Database\Scan\ResultStore;
use Joomla\Plugin\Healthcheck\Database\Scan\UnusedTables;
use Joomla\Plugin\Healthcheck\Database\Scan\ZeroDateScanner;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Health Check plugin showing database update problems, old zero dates and unused tables.
 * Read-only toward the scanned database.
 *
 * @since  __DEPLOY_VERSION__
 */
final class Database extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * Strings used by the admin script
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const SCRIPT_STRINGS = [
        'JCLOSE',
        'PLG_HEALTHCHECK_DATABASE_ACTION_REFUSED',
        'PLG_HEALTHCHECK_DATABASE_CHECKNOW_DONE',
        'PLG_HEALTHCHECK_DATABASE_CHECKNOW_ERROR',
        'PLG_HEALTHCHECK_DATABASE_CHECKNOW_FAILED',
        'PLG_HEALTHCHECK_DATABASE_CHECKNOW_PROGRESS',
        'PLG_HEALTHCHECK_DATABASE_CHECKNOW_STALLED',
        'PLG_HEALTHCHECK_DATABASE_CHECKNOW_STARTING',
        'PLG_HEALTHCHECK_DATABASE_REQUEST_FAILED',
        'PLG_HEALTHCHECK_DATABASE_UNUSED_NAME',
        'PLG_HEALTHCHECK_DATABASE_ZERODATES_NAME',
    ];

    /**
     * Data for this request, loaded once
     *
     * @var    array|null
     * @since  __DEPLOY_VERSION__
     */
    private ?array $data = null;

    /**
     * Whether the script options and strings were added to the page
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    private bool $assetsLoaded = false;

    /**
     * Constructor
     *
     * @param   array             $config     The plugin configuration
     * @param   IgnoreRepository  $ignores    The ignore entries
     * @param   ResultStore       $store      The cached scan results
     * @param   TableOwnership    $ownership  Works out which tables the installed extensions account for
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(
        array $config,
        private readonly IgnoreRepository $ignores,
        private readonly ResultStore $store,
        private readonly TableOwnership $ownership
    ) {
        parent::__construct($config);
    }

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onHealthcheckGetIcons'  => 'onHealthcheckGetIcons',
            'onHealthcheckGetTables' => 'onHealthcheckGetTables',
            'onAjaxDatabase'         => 'onAjaxDatabase',
        ];
    }

    /**
     * Adds the schema, zero-date, unused-table and Check now icons.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onHealthcheckGetIcons(HealthChecksEvent $event): void
    {
        if (!$this->isAllowed($event)) {
            return;
        }

        $data    = $this->data();
        $section = Text::_('PLG_HEALTHCHECK_DATABASE_SECTION');
        $token   = Session::getFormToken();

        $this->loadAssets($data);

        $icons = [[
            'id'      => 'plg_healthcheck_database_schema',
            'section' => $section,
            'name'    => 'PLG_HEALTHCHECK_DATABASE_SCHEMA_NAME',
            'icon'    => 'icon-database',
            'text'    => self::escape(Text::_('PLG_HEALTHCHECK_DATABASE_SCHEMA_TEXT')),
            'link'    => 'index.php?option=com_installer&amp;view=database',
            'ajaxurl' => 'index.php?option=com_ajax&amp;group=healthcheck&amp;plugin=database&amp;task=schema&amp;format=json&amp;' . $token . '=1',
        ]];

        if ($data['zeroDates']) {
            $icons[] = $this->zeroDatesIcon($data, $section);
        }

        if ($data['unused']) {
            $icons[] = $this->unusedIcon($data, $section);
        }

        if ($data['zeroDates'] || $data['unused']) {
            $checkNowText = match (true) {
                !$data['zeroDates'] => 'PLG_HEALTHCHECK_DATABASE_CHECKNOW_TEXT_UNUSED',
                !$data['unused']    => 'PLG_HEALTHCHECK_DATABASE_CHECKNOW_TEXT_ZERODATES',
                default             => 'PLG_HEALTHCHECK_DATABASE_CHECKNOW_TEXT',
            };

            $icons[] = [
                'id'      => 'plg_healthcheck_database_checknow',
                'section' => $section,
                'name'    => 'PLG_HEALTHCHECK_DATABASE_CHECKNOW_NAME',
                'icon'    => 'icon-sync',
                'text'    => self::escape(Text::_($checkNowText)),
                'link'    => '#',
                'onclick' => 'plgHealthcheckDatabaseCheckNow',
            ];
        }

        $this->append($event, $icons);
    }

    /**
     * Adds the table of open findings.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onHealthcheckGetTables(HealthChecksEvent $event): void
    {
        if (!$this->isAllowed($event)) {
            return;
        }

        $data = $this->data();
        $rows = self::tableRows($data['groups'], $data['findings'], $data['prefix']);

        if ($rows === []) {
            return;
        }

        $this->loadAssets($data);

        $labels = [
            'finding' => Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_FINDING'),
            'where'   => Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_WHERE'),
            'count'   => Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_COUNT'),
            'details' => Text::_('PLG_HEALTHCHECK_DATABASE_COLUMN_DETAILS'),
        ];

        $this->append($event, [[
            'id'      => 'plg_healthcheck_database_table',
            'section' => Text::_('PLG_HEALTHCHECK_DATABASE_SECTION'),
            'caption' => Text::_('PLG_HEALTHCHECK_DATABASE_TABLE_CAPTION'),
            'status'  => 'warning',
            'columns' => self::columns($labels),
            'data'    => $rows,
        ]]);
    }

    /**
     * Ajax entry point for the dashboard: one handler per task behind a shared guard.
     *
     * @param   AjaxEvent  $event  The Ajax event
     *
     * @return  void
     *
     * @throws  \RuntimeException  On a missing token or permission (403), a wrong method (405) or an unknown task (400)
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onAjaxDatabase(AjaxEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator') || !Session::checkToken('get') || !$app->getIdentity()->authorise('core.admin')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->loadLanguage();

        $input    = $app->getInput();
        $handlers = $this->ajaxHandlers();
        $task     = (string) $input->getCmd('task');

        if (!isset($handlers[$task])) {
            throw new \RuntimeException('Unknown task', 400);
        }

        [$postOnly, $handler] = $handlers[$task];

        if ($postOnly && strtoupper($input->getMethod()) !== 'POST') {
            throw new \RuntimeException('Method not allowed', 405);
        }

        $event->addResult($handler($input));
    }

    /**
     * Rows of the findings table: one per prefix group with open tables (own group first), then one per
     * table with open zero-date columns. Ignored items are left out.
     *
     * @param   array   $groups        The result of UnusedTables::groups()
     * @param   array   $zeroFindings  The result of ZeroDateScanner::findings()
     * @param   string  $sitePrefix    The site's table prefix
     *
     * @return  list<array{finding:string, where:string, count:int, kind:string, key:string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function tableRows(array $groups, array $zeroFindings, string $sitePrefix): array
    {
        $rows = [];

        foreach ($groups as $group) {
            $open = \count(array_filter($group['tables'], static fn (array $table): bool => !$table['ignored']));

            if ($open === 0) {
                continue;
            }

            $prefix = (string) $group['prefix'];
            $own    = !empty($group['own']);

            $rows[] = [
                'finding' => Text::_($own ? 'PLG_HEALTHCHECK_DATABASE_FINDING_UNUSED' : 'PLG_HEALTHCHECK_DATABASE_FINDING_UNUSED_OTHER'),
                'where'   => $prefix === '' ? Text::_('PLG_HEALTHCHECK_DATABASE_WHERE_NO_PREFIX') : $prefix,
                'count'   => $open,
                'kind'    => 'unused',
                'key'     => $own ? $sitePrefix : $prefix,
            ];
        }

        $tables = [];

        foreach ($zeroFindings as $finding) {
            if ($finding['ignored']) {
                continue;
            }

            $key = strtolower((string) $finding['table']);

            $tables[$key] ??= ['table' => (string) $finding['table'], 'count' => 0];
            $tables[$key]['count']++;
        }

        foreach ($tables as $table) {
            $rows[] = [
                'finding' => Text::_('PLG_HEALTHCHECK_DATABASE_FINDING_ZERODATES'),
                'where'   => $table['table'],
                'count'   => $table['count'],
                'kind'    => 'zerodates',
                'key'     => $table['table'],
            ];
        }

        return $rows;
    }

    /**
     * Columns of the findings table. Every custom renderer escapes what it prints.
     *
     * @param   array  $labels  Translated labels: finding, where, count, details
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function columns(array $labels): array
    {
        return [
            [
                'key'      => 'finding',
                'title'    => $labels['finding'],
                'type'     => 'custom',
                'renderer' => static fn ($value): string => self::escape($value),
            ],
            [
                'key'      => 'where',
                'title'    => $labels['where'],
                'type'     => 'custom',
                'renderer' => static fn ($value): string => '<code>' . self::escape($value) . '</code>',
            ],
            [
                'key'        => 'count',
                'title'      => $labels['count'],
                'type'       => 'badge',
                'badgeClass' => 'warning',
            ],
            [
                'key'      => 'details',
                'title'    => $labels['details'],
                'type'     => 'custom',
                'renderer' => static fn ($value, $item): string => '<button type="button" class="btn btn-sm btn-outline-secondary plg-hc-db-details"'
                    . ' data-hc-db-kind="' . self::escape($item['kind'] ?? '') . '"'
                    . ' data-hc-db-key="' . self::escape($item['key'] ?? '') . '">'
                    . self::escape($labels['details']) . '</button>',
            ],
        ];
    }

    /**
     * Task handlers: task => [POST only, handler(Input): array].
     *
     * @return  array<string, array{0:bool, 1:\Closure}>
     *
     * @since   __DEPLOY_VERSION__
     */
    private function ajaxHandlers(): array
    {
        return [
            'schema'    => [false, fn (Input $input): array => $this->schemaResult()],
            'zerodates' => [true, fn (Input $input): array => $this->zeroDatesTask($input->post->getInt('restart', 0) === 1)],
            'unused'    => [true, fn (Input $input): array => $this->unusedTask()],
            'details'   => [false, fn (Input $input): array => $this->detailsTask($input)],
            'ignore'    => [true, fn (Input $input): array => $this->ignoreTask($input, true)],
            'reopen'    => [true, fn (Input $input): array => $this->ignoreTask($input, false)],
        ];
    }

    /**
     * Run one zero-date batch within the time budget.
     *
     * A new pass starts when asked to, or when the last pass is complete and stale. A complete pass that is
     * still fresh is returned as is, without scanning (ZeroDateScanner::run() would only touch its time).
     *
     * The state is stored before each table is counted, with that table marked in progress, so a request
     * that dies on a huge table leaves the marker behind and the next request skips that table. While the
     * marker is younger than the longest a request may run, another request is counting that table: the
     * batch (even a restart) reports `busy` and stores nothing, which would revert the other's progress.
     *
     * @param   boolean  $restart  Start a new pass from the first table
     *
     * @return  array{complete:bool, error:bool, checked:int, total:int, busy:bool}
     *
     * @since   __DEPLOY_VERSION__
     */
    private function zeroDatesTask(bool $restart): array
    {
        $this->closeSession();

        $db      = $this->getDatabase();
        $catalog = CatalogFactory::for($db);

        if (!$this->params->get('check_zerodates', 1) || !$catalog->supportsZeroDates()) {
            return ['complete' => true, 'error' => false, 'checked' => 0, 'total' => 0, 'busy' => false];
        }

        $now       = (new Date())->toSql();
        $prefix    = $db->getPrefix();
        $hours     = $this->refreshHours();
        $budget    = (float) max(0, min(20, (int) $this->params->get('time_budget', 5)));
        $deadAfter = ZeroDateScanner::deadAfter($budget, (int) \ini_get('max_execution_time'));
        $clock     = static fn (): float => microtime(true);
        $scanner   = new ZeroDateScanner($catalog, $clock);
        $state     = $this->store->get('zerodates') ?? ZeroDateScanner::emptyState();
        $started   = ($state['pass'] ?? null) !== null;

        if (ZeroDateScanner::isBusy($state, $clock(), $deadAfter)) {
            return self::progressResult($state, true);
        }

        if ($started && !$restart && !empty($state['complete'])) {
            if (!ResultStore::isStale($state['updated'] ?? null, $hours, $now)) {
                return self::progressResult($state);
            }

            $restart = true;
        }

        // A missing pass is started by run() itself.
        if ($started && $restart) {
            $state = $scanner->start($state, $prefix, $now, $hours, $deadAfter);
        }

        $state = $scanner->run($state, $prefix, $budget, $now, $hours, $deadAfter, fn (array $state) => $this->store->put('zerodates', $state));

        $this->store->put('zerodates', $state);

        return self::progressResult($state);
    }

    /**
     * What a zero-date batch reports to the admin script. `error` means a table could not be checked: the
     * pass is not complete and Check now stops instead of retrying. `busy` means another request is counting
     * a table of the pass right now: nothing was done, ask again shortly.
     *
     * @param   array    $state  Zero-date scanner state
     * @param   boolean  $busy   Whether another request is running the pass
     *
     * @return  array{complete:bool, error:bool, checked:int, total:int, busy:bool}
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function progressResult(array $state, bool $busy = false): array
    {
        $error = !empty($state['error']);

        return ['complete' => !$error && !empty($state['complete']), 'error' => $error] + ZeroDateScanner::progress($state) + ['busy' => $busy];
    }

    /**
     * Write and close the session, so a long scan does not hold the session lock and block the same
     * user's other admin requests. The guard has already checked the token; nothing later needs the session.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function closeSession(): void
    {
        $this->getApplication()->getSession()->close();
    }

    /**
     * Rebuild the unused-table data now.
     *
     * @return  array{ok:bool}
     *
     * @since   __DEPLOY_VERSION__
     */
    private function unusedTask(): array
    {
        $this->closeSession();

        if (!$this->params->get('check_unused', 1)) {
            return ['ok' => false];
        }

        $this->refreshUnused(CatalogFactory::for($this->getDatabase()), (new Date())->toSql());

        return ['ok' => true];
    }

    /**
     * The Details popup for one prefix group or table, or for every finding of a kind with `all=1`.
     *
     * @param   Input  $input  The request
     *
     * @return  array{html:string}
     *
     * @throws  \RuntimeException  On an unknown kind (400)
     *
     * @since   __DEPLOY_VERSION__
     */
    private function detailsTask(Input $input): array
    {
        $kind = (string) $input->getCmd('kind');

        if (!\in_array($kind, ['unused', 'zerodates'], true)) {
            throw new \RuntimeException('Unknown kind', 400);
        }

        $key = $input->get('key', '', 'raw');
        $key = \is_string($key) ? $key : '';
        $all = $input->getInt('all', 0) === 1;

        // data() may rebuild a stale unused-table cache here: a metadata read (catalog + extension SQL files),
        // the same as a dashboard load. It never runs the zero-date scan.
        return ['html' => self::renderDetails(
            PluginHelper::getLayoutPath('healthcheck', 'database', 'details'),
            self::detailsData($kind, $this->data(), $key, $all)
        )];
    }

    /**
     * What the Details popup shows: the matching unused-table groups or zero-date findings, open rows first.
     *
     * Each table and column gets a state: `open`, `ignored` (an entry of its own) or `prefix` (ignored only
     * through an Ignore all of its group). Zero dates also list the tables skipped as too large and the table
     * the last run could not check.
     *
     * @param   string   $kind  `unused` or `zerodates`
     * @param   array    $data  Data for this request (groups, findings, ignores, state)
     * @param   string   $key   Prefix of the group, or table name, compared case-insensitively
     * @param   boolean  $all   Everything of that kind; the key is then ignored
     *
     * @return  array  Display data for tmpl/details.php
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function detailsData(string $kind, array $data, string $key, bool $all): array
    {
        $key       = strtolower($key);
        $ignores   = $data['ignores'];
        $openFirst = static fn (array $a, array $b): int => ($a['state'] !== 'open') <=> ($b['state'] !== 'open');

        if ($kind === 'zerodates') {
            $findings = [];

            foreach ($data['findings'] as $finding) {
                if ($all || strtolower($finding['table']) === $key) {
                    $findings[] = $finding + ['state' => $finding['ignored'] ? 'ignored' : 'open'];
                }
            }

            usort($findings, $openFirst);

            $state   = $data['state'] ?? [];
            $skipped = ['large' => [], 'name' => []];

            foreach ($state['skipped'] ?? [] as $skippedKey => $entry) {
                if ($all || strtolower((string) $skippedKey) === $key) {
                    // {name, at, reason}; a bare name, or no reason, was cached by an older version.
                    $reason             = \is_array($entry) && ($entry['reason'] ?? null) === 'name' ? 'name' : 'large';
                    $skipped[$reason][] = (string) (\is_array($entry) ? ($entry['name'] ?? $skippedKey) : $entry);
                }
            }

            $error = $state['error']['table'] ?? null;

            if ($error !== null && !$all && strtolower($error) !== $key) {
                $error = null;
            }

            return ['kind' => $kind, 'findings' => $findings, 'skipped' => $skipped['large'], 'skippedName' => $skipped['name'], 'error' => $error];
        }

        $groups = [];

        foreach ($data['groups'] as $group) {
            // With an empty site prefix every table is in the own group, whose prefix is then '' too.
            if (!$all && strtolower($group['prefix']) !== $key) {
                continue;
            }

            foreach ($group['tables'] as &$table) {
                $table['state'] = match (true) {
                    $ignores->hasTableEntry($table['name']) => 'ignored',
                    $group['prefixIgnored']                 => 'prefix',
                    default                                 => 'open',
                };
            }

            unset($table);
            usort($group['tables'], $openFirst);

            $group['open'] = $group['tables'] !== [] && $group['tables'][0]['state'] === 'open';
            $groups[]      = $group;
        }

        usort($groups, static fn (array $a, array $b): int => $b['open'] <=> $a['open']);

        return ['kind' => $kind, 'groups' => $groups];
    }

    /**
     * Render the details layout.
     *
     * @param   string  $layout       Path of the layout file
     * @param   array   $displayData  The result of detailsData()
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function renderDetails(string $layout, array $displayData): string
    {
        ob_start();

        try {
            include $layout;
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * Ignore or Reopen a table, a column or a prefix. Counts follow at read time; nothing is rescanned.
     *
     * @param   Input    $input  The request
     * @param   boolean  $add    Ignore (true) or Reopen (false)
     *
     * @return  array{ok:bool}
     *
     * @throws  \RuntimeException  On an unknown kind or an Ignore all of the site's own prefix (400)
     *
     * @since   __DEPLOY_VERSION__
     */
    private function ignoreTask(Input $input, bool $add): array
    {
        $kind = (string) $input->post->getCmd('kind');
        $name = $input->post->get('name', '', 'raw');

        if (!\in_array($kind, ['table', 'column', 'prefix'], true) || !\is_string($name)) {
            throw new \RuntimeException('Invalid ignore entry', 400);
        }

        if ($add && $kind === 'prefix' && strtolower($name) === strtolower($this->getDatabase()->getPrefix())) {
            throw new \RuntimeException('The site prefix cannot be ignored', 400);
        }

        if (!$add) {
            return ['ok' => $this->ignores->remove($kind, $name)];
        }

        return ['ok' => $this->ignores->add($kind, $name, (int) $this->getApplication()->getIdentity()->id, (new Date())->toSql())];
    }

    /**
     * Hours a stored result stays fresh.
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private function refreshHours(): int
    {
        return max(1, min(168, (int) $this->params->get('refresh_hours', 24)));
    }

    /**
     * Extensions with database update problems, from core's own check in Extensions > Database.
     *
     * @return  array{amount:int, status:string, problems:int, extensions:int, text:string, label:string}
     *          A warning with the unavailable text when core's check fails
     *
     * @since   __DEPLOY_VERSION__
     */
    private function schemaResult(): array
    {
        try {
            $model = $this->getApplication()->bootComponent('com_installer')->getMVCFactory()
                ->createModel('Database', 'Administrator', ['ignore_request' => true]);

            return self::schemaSummary($model->getItems() ?: []);
        } catch (\Throwable $e) {
            Log::add('Database Health Check: the schema check failed: ' . $e->getMessage(), Log::WARNING, 'healthcheck');

            $text = Text::_('PLG_HEALTHCHECK_DATABASE_UNAVAILABLE');

            // A warning, not 0 and healthy: core's async script shows the amount and status as given.
            return ['amount' => 0, 'status' => 'warning', 'problems' => 0, 'extensions' => 0, 'text' => self::escape($text), 'label' => $text];
        }
    }

    /**
     * Map the items of com_installer's DatabaseModel::getItems() to the schema icon values.
     *
     * @param   array  $items  Items with an errorsCount key
     *
     * @return  array{amount:int, status:string, problems:int, extensions:int, text:string, label:string}
     *          `text` is escaped HTML, `label` the same as plain text for textContent
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function schemaSummary(array $items): array
    {
        $amount   = 0;
        $problems = 0;

        foreach ($items as $item) {
            $errors = (int) ($item['errorsCount'] ?? 0);

            if ($errors > 0) {
                $amount++;
                $problems += $errors;
            }
        }

        $text = $problems > 0
            ? self::inPhrase(
                Text::plural('PLG_HEALTHCHECK_DATABASE_N_PROBLEMS', $problems),
                Text::plural('PLG_HEALTHCHECK_DATABASE_N_EXTENSIONS', $amount)
            )
            : Text::plural('PLG_HEALTHCHECK_DATABASE_SCHEMA_MATCH', \count($items));

        return [
            'amount'     => $amount,
            'status'     => $amount > 0 ? 'warning' : 'success',
            'problems'   => $problems,
            'extensions' => \count($items),
            'text'       => self::escape($text),
            'label'      => $text,
        ];
    }

    /**
     * "<count> in <count>" from two plural fragments.
     *
     * @param   string  $what   The counted things, e.g. "3 tables"
     * @param   string  $where  Where they are, e.g. "2 groups"
     *
     * @return  string  Plain text
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function inPhrase(string $what, string $where): string
    {
        return Text::sprintf('PLG_HEALTHCHECK_DATABASE_N_IN_M', $what, $where);
    }

    /**
     * Rebuild the unused-table inputs (catalog tables and the accounted names) and store them.
     *
     * @param   Catalog  $catalog  The catalog
     * @param   string   $now      Current time (Y-m-d H:i:s, UTC)
     *
     * @return  array{updated:string, tables:array, accounted:string[]}
     *
     * @since   __DEPLOY_VERSION__
     */
    private function refreshUnused(Catalog $catalog, string $now): array
    {
        $db    = $this->getDatabase();
        $query = $db->createQuery()
            ->select($db->quoteName(['type', 'element', 'folder', 'client_id']))
            ->from($db->quoteName('#__extensions'))
            ->whereIn($db->quoteName('type'), ['component', 'module', 'plugin'], ParameterType::STRING);

        $value = [
            'updated'   => $now,
            'tables'    => $catalog->tables(),
            'accounted' => $this->ownership->accounted($db->setQuery($query)->loadObjectList() ?: []),
        ];

        $this->store->put('unused', $value);

        return $value;
    }

    /**
     * Results for this request, loaded once. Never runs the zero-date scan, and rebuilds the unused-table
     * list only when none is stored.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $db    = $this->getDatabase();
        $now   = (new Date())->toSql();
        $hours = $this->refreshHours();

        $data = [
            'prefix'     => $db->getPrefix(),
            'zeroDates'  => false,
            'unused'     => false,
            'unusedFail' => false,
            'unusedDue'  => false,
            'groups'     => [],
            'state'      => ZeroDateScanner::emptyState(),
            'findings'   => [],
            'ignores'    => new IgnoreList([]),
            'due'        => false,
        ];

        try {
            $data['ignores'] = new IgnoreList($this->ignores->all());
        } catch (\Throwable $e) {
            Log::add('Database Health Check: could not read the ignore list: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
        }

        try {
            $catalog = CatalogFactory::for($db);
        } catch (\Throwable $e) {
            Log::add('Database Health Check: ' . $e->getMessage(), Log::WARNING, 'healthcheck');

            $data['unused']     = (bool) $this->params->get('check_unused', 1);
            $data['unusedFail'] = $data['unused'];

            return $this->data = $data;
        }

        if ($this->params->get('check_unused', 1)) {
            $data['unused'] = true;
            $stored         = $this->store->get('unused');

            // Only a missing list is built now. A stale one is shown as is and the admin script refreshes it
            // in the background (task=unused); the next page load shows the new list.
            if ($stored === null) {
                try {
                    $stored = $this->refreshUnused($catalog, $now);
                } catch (\Throwable $e) {
                    Log::add('Database Health Check: could not list the tables: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
                }
            } elseif (ResultStore::isStale($stored['updated'] ?? null, $hours, $now)) {
                $data['unusedDue'] = true;
            }

            if ($stored === null) {
                $data['unusedFail'] = true;
            } else {
                $data['groups'] = UnusedTables::groups($stored['tables'] ?? [], $stored['accounted'] ?? [], $data['prefix'], $data['ignores']);
            }
        }

        try {
            $data['zeroDates'] = (bool) $this->params->get('check_zerodates', 1) && $catalog->supportsZeroDates();
        } catch (\Throwable $e) {
            Log::add('Database Health Check: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
        }

        if ($data['zeroDates']) {
            $data['state']    = $this->store->get('zerodates') ?? ZeroDateScanner::emptyState();
            $data['findings'] = ZeroDateScanner::findings($data['state'], $data['ignores']);
            $data['due']      = ResultStore::isStale($data['state']['updated'] ?? null, $hours, $now) || empty($data['state']['complete']);
        }

        return $this->data = $data;
    }

    /**
     * The zero-date icon.
     *
     * @param   array   $data     Data for this request
     * @param   string  $section  Section label
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function zeroDatesIcon(array $data, string $section): array
    {
        $state    = $data['state'];
        $findings = $data['findings'];
        $open     = array_filter($findings, static fn (array $finding): bool => !$finding['ignored']);
        $ignored  = \count($findings) - \count($open);
        $tables   = \count(array_unique(array_map(static fn (array $finding): string => strtolower($finding['table']), $open)));
        $updated  = $state['updated'] ?? null;
        $never    = $updated === null && empty($state['results']);
        $error    = $state['error']['table'] ?? null;
        $byName   = \count(array_filter($state['skipped'] ?? [], static fn ($entry): bool => \is_array($entry) && ($entry['reason'] ?? null) === 'name'));
        $skipped  = \count($state['skipped'] ?? []) - $byName;
        $lines    = [];

        if ($open) {
            $lines[] = self::inPhrase(
                Text::plural('PLG_HEALTHCHECK_DATABASE_N_COLUMNS', \count($open)),
                Text::plural('PLG_HEALTHCHECK_DATABASE_N_TABLES', $tables)
            );
        } elseif (!$never && $error === null && $skipped === 0 && $byName === 0) {
            $lines[] = Text::_('PLG_HEALTHCHECK_DATABASE_ZERODATES_NONE');
        } elseif (($state['pass'] ?? null) === null) {
            $lines[] = Text::_('PLG_HEALTHCHECK_DATABASE_ZERODATES_NEVER');
        }

        if ($error !== null) {
            $lines[] = Text::sprintf('PLG_HEALTHCHECK_DATABASE_ZERODATES_ERROR', $error);
        }

        if ($skipped > 0) {
            $lines[] = Text::plural('PLG_HEALTHCHECK_DATABASE_ZERODATES_SKIPPED', $skipped);
        }

        if ($byName > 0) {
            $lines[] = Text::plural('PLG_HEALTHCHECK_DATABASE_ZERODATES_NAME_SKIPPED', $byName);
        }

        // One status line: the progress while a pass is unfinished, otherwise when the last pass finished.
        if (empty($state['complete']) && ($state['pass'] ?? null) !== null) {
            $progress = ZeroDateScanner::progress($state);
            $lines[]  = Text::sprintf('PLG_HEALTHCHECK_DATABASE_ZERODATES_PARTIAL', $progress['checked'], $progress['total']);
        } elseif ($updated !== null) {
            $lines[] = Text::sprintf('PLG_HEALTHCHECK_DATABASE_ZERODATES_CHECKED', HTMLHelper::_('date.relative', $updated, null, null));
        }

        if ($ignored > 0) {
            $lines[] = Text::plural('PLG_HEALTHCHECK_DATABASE_IGNORED', $ignored);
        }

        $icon = [
            'id'      => 'plg_healthcheck_database_zerodates',
            'section' => $section,
            'name'    => 'PLG_HEALTHCHECK_DATABASE_ZERODATES_NAME',
            'icon'    => 'icon-calendar',
            'text'    => self::lines($lines),
            'link'    => '#',
            'onclick' => 'plgHealthcheckDatabaseZeroDates',
        ];

        // Never checked: no amount or status, a 0 would read as healthy. A table that could not be checked
        // is never healthy.
        if (!$never || $error !== null) {
            $icon['amount'] = \count($open);
            $icon['status'] = ($open || $error !== null) ? 'warning' : 'success';
        }

        return $icon;
    }

    /**
     * The unused-table icon.
     *
     * @param   array   $data     Data for this request
     * @param   string  $section  Section label
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function unusedIcon(array $data, string $section): array
    {
        $icon = [
            'id'      => 'plg_healthcheck_database_unused',
            'section' => $section,
            'name'    => 'PLG_HEALTHCHECK_DATABASE_UNUSED_NAME',
            'icon'    => 'icon-list',
            'link'    => '#',
            'onclick' => 'plgHealthcheckDatabaseUnused',
        ];

        if ($data['unusedFail']) {
            $icon['text'] = self::escape(Text::_('PLG_HEALTHCHECK_DATABASE_UNAVAILABLE'));

            return $icon;
        }

        $groups   = $data['groups'];
        $open     = UnusedTables::openCount($groups);
        $ignored  = UnusedTables::ignoredCount($groups);
        $withOpen = \count(array_filter(
            $groups,
            static fn (array $group): bool => (bool) array_filter($group['tables'], static fn (array $table): bool => !$table['ignored'])
        ));

        $lines = [$open > 0
            ? self::inPhrase(Text::plural('PLG_HEALTHCHECK_DATABASE_N_TABLES', $open), Text::plural('PLG_HEALTHCHECK_DATABASE_N_GROUPS', $withOpen))
            : Text::_('PLG_HEALTHCHECK_DATABASE_UNUSED_NONE')];

        if ($ignored > 0) {
            $lines[] = Text::plural('PLG_HEALTHCHECK_DATABASE_IGNORED', $ignored);
        }

        return $icon + [
            'amount' => $open,
            'text'   => self::lines($lines),
            'status' => $open > 0 ? 'warning' : 'success',
        ];
    }

    /**
     * Add the admin script, its options and strings to the page, once.
     *
     * @param   array  $data  Data for this request
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadAssets(array $data): void
    {
        if ($this->assetsLoaded) {
            return;
        }

        $this->assetsLoaded = true;
        $document           = $this->getApplication()->getDocument();

        if (!$document) {
            return;
        }

        if (method_exists($document, 'getWebAssetManager')) {
            $wa = $document->getWebAssetManager();
            $wa->getRegistry()->addExtensionRegistryFile('plg_healthcheck_database');
            $wa->useScript('plg_healthcheck_database.admin');
        }

        $document->addScriptOptions('plg_healthcheck_database', [
            'ajax'         => 'index.php?option=com_ajax&group=healthcheck&plugin=database&format=json',
            'token'        => Session::getFormToken(),
            'unusedDue'    => $data['unused'] && $data['unusedDue'],
            'zeroDatesDue' => $data['zeroDates'] && $data['due'],
        ]);

        foreach (self::SCRIPT_STRINGS as $key) {
            Text::script($key);
        }
    }

    /**
     * Right context and a Super User.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function isAllowed(HealthChecksEvent $event): bool
    {
        if ($event->getContext() !== $this->params->get('context', 'general') || !$this->getApplication()->getIdentity()->authorise('core.admin')) {
            return false;
        }

        $this->loadLanguage();

        return true;
    }

    /**
     * Escape text lines and join them for an icon.
     *
     * @param   string[]  $lines  Plain text lines
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function lines(array $lines): string
    {
        return implode('<br>', array_map(static fn (string $line): string => self::escape($line), $lines));
    }

    /**
     * Escape a value for HTML output, attributes included.
     *
     * @param   mixed  $value  A value to print in HTML
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Append one group of layout items to the event result.
     *
     * @param   HealthChecksEvent  $event  The event
     * @param   array              $items  Items for the layout
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function append(HealthChecksEvent $event, array $items): void
    {
        $result   = $event->getArgument('result', []);
        $result[] = $items;

        $event->setArgument('result', $result);
    }
}
