<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Database\Extension;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Cache\Controller\OutputController;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Document\Document;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\Database\DatabaseInterface;
use Joomla\Input\Input;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;
use Joomla\Plugin\Healthcheck\Database\Extension\Database;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreList;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreRepository;
use Joomla\Plugin\Healthcheck\Database\Ownership\TableOwnership;
use Joomla\Plugin\Healthcheck\Database\Scan\ResultStore;
use Joomla\Plugin\Healthcheck\Database\Scan\UnusedTables;
use Joomla\Plugin\Healthcheck\Database\Scan\ZeroDateScanner;
use Joomla\Session\SessionInterface;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the Database health check findings table
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The Database health check findings table')]
class DatabaseTest extends UnitTestCase
{
    private const EVIL = '<img src=x onerror=1>';

    /**
     * @var    array  $_GET before the test
     * @since  __DEPLOY_VERSION__
     */
    private array $get = [];

    /**
     * @var    string|null  REQUEST_METHOD before the test
     * @since  __DEPLOY_VERSION__
     */
    private ?string $method = null;

    /**
     * @var    integer  Queries the stub database was asked to build
     * @since  __DEPLOY_VERSION__
     */
    private int $queries = 0;

    /**
     * @var    integer  Values the stub cache was asked to store
     * @since  __DEPLOY_VERSION__
     */
    private int $stores = 0;

    /**
     * @var    array  $_POST before the test
     * @since  __DEPLOY_VERSION__
     */
    private array $post = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->get    = $_GET;
        $this->post   = $_POST;
        $this->method = $_SERVER['REQUEST_METHOD'] ?? null;

        $language = $this->createStub(Language::class);
        $language->method('_')->willReturnArgument(0);
        $language->method('getPluralSuffixes')->willReturn(['MORE']);
        $language->method('hasKey')->willReturn(false);
        Factory::$language = $language;

        HTMLHelper::register('date', static fn ($value): string => 'DATE(' . $value . ')');
        HTMLHelper::register('date.relative', static fn ($value): string => 'AGO(' . $value . ')');
    }

    protected function tearDown(): void
    {
        HTMLHelper::unregister('date');
        HTMLHelper::unregister('date.relative');
        Factory::$language    = null;
        Factory::$application = null;
        $_GET                 = $this->get;
        $_POST                = $this->post;

        if ($this->method === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->method;
        }

        parent::tearDown();
    }

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
        return array_map(static fn ($name) => ['name' => $name, 'rows' => 1, 'bytes' => 1024, 'updated' => null], $names);
    }

    /**
     * Zero-date state with one result per table.
     *
     * @param   array<string, string[]>  $tables  Table name => column names
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function zeroState(array $tables): array
    {
        $state = ZeroDateScanner::emptyState();

        foreach ($tables as $table => $columns) {
            $state['results'][strtolower($table)] = [
                'table'   => $table,
                'checked' => '2026-10-17 10:00:00',
                'columns' => array_map(static fn ($column) => ['column' => $column, 'type' => 'datetime', 'zero' => 3], $columns),
            ];
        }

        return $state;
    }

    #[TestDox('One row per group with open tables, own group first, counting open tables only')]
    public function testUnusedRows(): void
    {
        $ignores = new IgnoreList([['kind' => 'table', 'name' => 'hc_orphan_b'], ['kind' => 'table', 'name' => 'old_x']]);
        $groups  = UnusedTables::groups(
            $this->tables(['old_x', 'hc_orphan_a', 'hc_orphan_b', 'hc_orphan_c', 'kiufs_content', 'kiufs_users']),
            ['#__content'],
            'hc_',
            $ignores
        );

        $rows = Database::tableRows($groups, [], 'hc_');

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['finding' => 'PLG_HEALTHCHECK_DATABASE_FINDING_UNUSED', 'where' => 'hc_', 'count' => 2, 'kind' => 'unused', 'key' => 'hc_'],
            $rows[0]
        );
        $this->assertSame(
            ['finding' => 'PLG_HEALTHCHECK_DATABASE_FINDING_UNUSED_OTHER', 'where' => 'kiufs_', 'count' => 2, 'kind' => 'unused', 'key' => 'kiufs_'],
            $rows[1]
        );
    }

    #[TestDox('A fully ignored group and a prefix-ignored group give no row')]
    public function testIgnoredGroupsGiveNoRow(): void
    {
        $ignores = new IgnoreList([['kind' => 'table', 'name' => 'hc_orphan'], ['kind' => 'prefix', 'name' => 'kiufs_']]);
        $groups  = UnusedTables::groups($this->tables(['hc_orphan', 'kiufs_content', 'kiufs_users']), [], 'hc_', $ignores);

        $this->assertSame([], Database::tableRows($groups, [], 'hc_'));
    }

    #[TestDox('Tables without a prefix are labelled as such')]
    public function testNoPrefixGroup(): void
    {
        $groups = UnusedTables::groups($this->tables(['legacy']), [], 'hc_', new IgnoreList([]));
        $rows   = Database::tableRows($groups, [], 'hc_');

        $this->assertSame('PLG_HEALTHCHECK_DATABASE_WHERE_NO_PREFIX', $rows[0]['where']);
        $this->assertSame('', $rows[0]['key']);
    }

    #[TestDox('One row per table with open zero-date columns, counting its open columns')]
    public function testZeroDateRows(): void
    {
        $ignores  = new IgnoreList([['kind' => 'column', 'name' => 'hc_a.modified'], ['kind' => 'column', 'name' => 'hc_b.created']]);
        $findings = ZeroDateScanner::findings($this->zeroState(['hc_a' => ['created', 'modified', 'publish_up'], 'hc_b' => ['created']]), $ignores);

        $this->assertSame(
            [['finding' => 'PLG_HEALTHCHECK_DATABASE_FINDING_ZERODATES', 'where' => 'hc_a', 'count' => 2, 'kind' => 'zerodates', 'key' => 'hc_a']],
            Database::tableRows([], $findings, 'hc_')
        );
    }

    #[TestDox('Unused rows come before zero-date rows')]
    public function testRowOrder(): void
    {
        $groups   = UnusedTables::groups($this->tables(['hc_orphan']), [], 'hc_', new IgnoreList([]));
        $findings = ZeroDateScanner::findings($this->zeroState(['hc_content' => ['created']]), new IgnoreList([]));

        $this->assertSame(['unused', 'zerodates'], array_column(Database::tableRows($groups, $findings, 'hc_'), 'kind'));
    }

    #[TestDox('HTML in table names is escaped by every custom renderer')]
    public function testHtmlInNamesIsEscaped(): void
    {
        $findings = ZeroDateScanner::findings($this->zeroState([self::EVIL => ['created']]), new IgnoreList([]));
        $rows     = Database::tableRows([], $findings, 'hc_');
        $labels   = ['finding' => 'Finding', 'where' => 'Where', 'count' => 'Count', 'details' => 'Details'];
        $html     = '';

        foreach (Database::columns($labels) as $column) {
            $this->assertNotSame('', $column['title']);

            if (($column['type'] ?? '') === 'custom') {
                $html .= $column['renderer']($rows[0][$column['key']] ?? '', $rows[0]);
            }
        }

        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    /**
     * A plugin wired with stubs: no ignore entries, cached results as given, a Super User.
     *
     * @param   array  $params  Plugin params
     * @param   array  $cached  Cache key => stored value
     * @param   array  $opts    server (type), admin (core.admin), client, input (data)
     *
     * @return  Database
     *
     * @since   __DEPLOY_VERSION__
     */
    private function plugin(array $params, array $cached, array $opts = []): Database
    {
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('getServerType')->willReturn($opts['server'] ?? 'mysql');
        $db->method('getPrefix')->willReturn('hc_');
        $db->method('getQuery')->willThrowException(new \RuntimeException('no database in unit tests'));
        $db->method('createQuery')->willReturnCallback(function () {
            $this->queries++;

            throw new \RuntimeException('no database in unit tests');
        });

        $cache = $this->createStub(OutputController::class);
        $cache->method('get')->willReturnCallback(static fn ($key) => $cached[$key] ?? false);
        $cache->method('store')->willReturnCallback(function (): bool {
            $this->stores++;

            return true;
        });

        $factory = $this->createStub(CacheControllerFactoryInterface::class);
        $factory->method('createCacheController')->willReturn($cache);

        $user = $this->createStub(User::class);
        $user->method('authorise')->willReturn($opts['admin'] ?? true);

        $session = $opts['session'] ?? $this->createStub(SessionInterface::class);
        $session->method('get')->willReturn($user);
        $session->method('getToken')->willReturn('t');

        $app = $this->createStub(AdministratorApplication::class);
        $app->method('getDocument')->willReturn($opts['document'] ?? null);

        if (!empty($opts['bootFails'])) {
            $app->method('bootComponent')->willThrowException(new \RuntimeException('com_installer is broken'));
        }

        $app->method('getIdentity')->willReturn($user);
        $app->method('getSession')->willReturn($session);
        $app->method('get')->willReturn('secret');
        $app->method('isClient')->willReturnCallback(static fn ($client) => $client === ($opts['client'] ?? 'administrator'));
        $app->method('getInput')->willReturn(new Input($opts['input'] ?? []));
        Factory::$application = $app;

        $plugin = new Database(
            ['name' => 'database', 'type' => 'healthcheck', 'params' => json_encode($params + ['context' => 'general'])],
            new IgnoreRepository($db),
            new ResultStore($factory),
            new TableOwnership('/nonexistent', '/nonexistent')
        );
        $plugin->setApplication($app);
        $plugin->setDatabase($db);

        return $plugin;
    }

    /**
     * Icons added by the plugin, keyed by id.
     *
     * @param   Database  $plugin  The plugin
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function icons(Database $plugin): array
    {
        $event = new HealthChecksEvent('onHealthcheckGetIcons', ['context' => 'general']);
        $plugin->onHealthcheckGetIcons($event);

        return array_column($event->getArgument('result')[0], null, 'id');
    }

    #[TestDox('The icons come in order, all in the Database section, with a live schema icon')]
    public function testIcons(): void
    {
        $now    = (new Date())->toSql();
        $unused = ['updated' => $now, 'tables' => $this->tables(['hc_orphan', 'kiufs_a', 'kiufs_b']), 'accounted' => []];
        $icons  = $this->icons($this->plugin([], ['unused' => $unused]));

        $this->assertSame(
            ['plg_healthcheck_database_schema', 'plg_healthcheck_database_zerodates', 'plg_healthcheck_database_unused', 'plg_healthcheck_database_checknow'],
            array_keys($icons)
        );
        $this->assertSame(['PLG_HEALTHCHECK_DATABASE_SECTION'], array_values(array_unique(array_column($icons, 'section'))));

        $schema = $icons['plg_healthcheck_database_schema'];
        $this->assertStringContainsString('&amp;task=schema&amp;format=json&amp;', $schema['ajaxurl']);
        $this->assertStringEndsWith('=1', $schema['ajaxurl']);
        $this->assertArrayNotHasKey('amount', $schema);
        $this->assertArrayNotHasKey('status', $schema);

        $zero = $icons['plg_healthcheck_database_zerodates'];
        $this->assertSame('PLG_HEALTHCHECK_DATABASE_ZERODATES_NEVER', $zero['text']);
        $this->assertArrayNotHasKey('amount', $zero);
        $this->assertArrayNotHasKey('status', $zero);

        $this->assertSame(3, $icons['plg_healthcheck_database_unused']['amount']);
        $this->assertSame('warning', $icons['plg_healthcheck_database_unused']['status']);
        $this->assertSame('plgHealthcheckDatabaseUnused', $icons['plg_healthcheck_database_unused']['onclick']);
        $this->assertArrayNotHasKey('status', $icons['plg_healthcheck_database_checknow']);
    }

    #[TestDox('A partial zero-date pass shows its results and only the progress line')]
    public function testZeroDatesPartial(): void
    {
        $state             = $this->zeroState(['hc_a' => ['created', 'modified']]);
        $state['pass']     = '2026-10-17 10:00:00';
        $state['cursor']   = 1;
        $state['tables']   = ['hc_a' => [], 'hc_b' => [], 'hc_c' => []];
        $state['updated']  = '2026-10-16 10:00:00';
        $state['complete'] = false;

        $icons = $this->icons($this->plugin(['check_unused' => 0], ['zerodates' => $state]));
        $zero  = $icons['plg_healthcheck_database_zerodates'];

        $this->assertArrayNotHasKey('plg_healthcheck_database_unused', $icons);
        $this->assertSame(2, $zero['amount']);
        $this->assertSame('warning', $zero['status']);
        $this->assertSame(
            'PLG_HEALTHCHECK_DATABASE_N_IN_M<br>PLG_HEALTHCHECK_DATABASE_ZERODATES_PARTIAL',
            $zero['text']
        );
    }

    #[TestDox('A complete zero-date pass shows the summary and exactly one relative Checked line')]
    public function testZeroDatesComplete(): void
    {
        // Let the Checked string keep its placeholder so the formatted argument is visible.
        $language = $this->createStub(Language::class);
        $language->method('_')->willReturnCallback(
            static fn ($key) => $key === 'PLG_HEALTHCHECK_DATABASE_ZERODATES_CHECKED' ? 'CHECKED %s' : $key
        );
        $language->method('getPluralSuffixes')->willReturn(['MORE']);
        $language->method('hasKey')->willReturn(false);
        Factory::$language = $language;

        $state             = $this->zeroState(['hc_a' => ['created', 'modified']]);
        $state['pass']     = '2026-10-17 10:00:00';
        $state['updated']  = '2026-10-17 10:05:00';
        $state['complete'] = true;

        $icons = $this->icons($this->plugin(['check_unused' => 0], ['zerodates' => $state]));
        $zero  = $icons['plg_healthcheck_database_zerodates'];

        $this->assertSame('PLG_HEALTHCHECK_DATABASE_N_IN_M<br>CHECKED AGO(2026-10-17 10:05:00)', $zero['text']);
        $this->assertStringNotContainsString('DATE(', $zero['text']);
        $this->assertStringNotContainsString('PARTIAL', $zero['text']);
        $this->assertSame(1, substr_count($zero['text'], 'CHECKED'));
    }

    #[TestDox('With both checks off only the schema icon is shown')]
    public function testChecksOff(): void
    {
        $icons = $this->icons($this->plugin(['check_unused' => 0, 'check_zerodates' => 0], []));

        $this->assertSame(['plg_healthcheck_database_schema'], array_keys($icons));
    }

    /**
     * Use the plugin's real en-GB strings with English plural suffixes.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function english(): void
    {
        $strings  = parse_ini_file(JPATH_ROOT . '/administrator/language/en-GB/plg_healthcheck_database.ini');
        $language = $this->createStub(Language::class);
        $language->method('_')->willReturnCallback(static fn ($key) => $strings[$key] ?? $key);
        $language->method('hasKey')->willReturnCallback(static fn ($key) => isset($strings[$key]));
        $language->method('getPluralSuffixes')->willReturnCallback(static fn ($n) => match ((int) $n) {
            0       => ['0'],
            1       => ['ONE', '1'],
            default => ['OTHER', 'MORE'],
        });
        Factory::$language = $language;
    }

    #[TestDox('The schema result counts extensions with problems, the problems and the extensions checked')]
    public function testSchemaSummary(): void
    {
        $this->english();

        $this->assertSame(
            ['amount' => 2, 'status' => 'warning', 'problems' => 3, 'extensions' => 3, 'text' => '3 problems in 2 extensions', 'label' => '3 problems in 2 extensions'],
            Database::schemaSummary([['errorsCount' => 2], ['errorsCount' => 0], ['errorsCount' => 1]])
        );
        $this->assertSame('1 problem in 1 extension', Database::schemaSummary([['errorsCount' => 1]])['text']);
        $this->assertSame(
            ['amount' => 0, 'status' => 'success', 'problems' => 0, 'extensions' => 4, 'text' => 'All 4 extensions match their schema', 'label' => 'All 4 extensions match their schema'],
            Database::schemaSummary(array_fill(0, 4, ['errorsCount' => 0]))
        );
        $this->assertSame('1 extension matches its schema', Database::schemaSummary([['errorsCount' => 0]])['text']);
    }

    #[TestDox('Icon summaries use singular and plural forms')]
    public function testPlurals(): void
    {
        $this->english();

        $now    = (new Date())->toSql();
        $unused = ['updated' => $now, 'tables' => $this->tables(['hc_orphan']), 'accounted' => []];
        $state  = $this->zeroState(['hc_a' => ['created']]);

        $state['updated']  = $now;
        $state['complete'] = true;

        $icons = $this->icons($this->plugin([], ['unused' => $unused, 'zerodates' => $state]));

        $this->assertSame('1 table in 1 group', $icons['plg_healthcheck_database_unused']['text']);
        $this->assertStringStartsWith('1 column in 1 table<br>', $icons['plg_healthcheck_database_zerodates']['text']);
    }

    #[TestDox('The unused icon says unavailable when the refresh fails and nothing is cached')]
    public function testUnusedUnavailable(): void
    {
        $unused = $this->icons($this->plugin(['check_zerodates' => 0], []))['plg_healthcheck_database_unused'];

        $this->assertSame('PLG_HEALTHCHECK_DATABASE_UNAVAILABLE', $unused['text']);
        $this->assertArrayNotHasKey('amount', $unused);
        $this->assertArrayNotHasKey('status', $unused);
    }

    #[TestDox('The unused icon says unavailable on an unsupported database driver')]
    public function testUnsupportedDriver(): void
    {
        $icons = $this->icons($this->plugin([], [], ['server' => 'sqlsrv']));

        $this->assertArrayNotHasKey('plg_healthcheck_database_zerodates', $icons);
        $this->assertSame('PLG_HEALTHCHECK_DATABASE_UNAVAILABLE', $icons['plg_healthcheck_database_unused']['text']);
        $this->assertArrayNotHasKey('status', $icons['plg_healthcheck_database_unused']);
    }

    /**
     * Dispatch onAjaxDatabase and return the exception code, or 0 when none was thrown.
     *
     * @param   array    $opts       Options for plugin()
     * @param   boolean  $withToken  Whether the query carries the form token
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private function ajaxCode(array $opts, bool $withToken = true): int
    {
        $result = $this->ajaxResult($opts, $withToken);

        return \is_int($result) ? $result : 0;
    }

    /**
     * Dispatch onAjaxDatabase and return the task result, or the exception code.
     *
     * @param   array    $opts       Options for plugin(), plus cached (cache key => value)
     * @param   boolean  $withToken  Whether the query carries the form token
     *
     * @return  array|integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private function ajaxResult(array $opts, bool $withToken = true): array|int
    {
        $plugin = $this->plugin([], $opts['cached'] ?? [], $opts);
        $_GET   = $withToken ? [Session::getFormToken() => '1'] : ['bad' => '1'];
        $event  = new AjaxEvent('onAjaxDatabase', ['subject' => Factory::$application]);

        try {
            $plugin->onAjaxDatabase($event);
        } catch (\RuntimeException $e) {
            return $e->getCode();
        }

        return $event->getArgument('result')[0];
    }

    /**
     * A document that keeps script options, with a stub asset manager.
     *
     * @return  Document
     *
     * @since   __DEPLOY_VERSION__
     */
    private function document(): Document
    {
        return new class ($this->createStub(WebAssetManager::class)) extends Document {
            public function __construct(private WebAssetManager $assets)
            {
            }

            public function getWebAssetManager(): WebAssetManager
            {
                return $this->assets;
            }
        };
    }

    #[TestDox('The Ajax handler refuses a bad token, a non Super User and the site client, and an unknown task')]
    public function testAjaxGuard(): void
    {
        $task = ['input' => ['task' => 'nope']];

        $this->assertSame(403, $this->ajaxCode($task, false));
        $this->assertSame(403, $this->ajaxCode($task + ['admin' => false]));
        $this->assertSame(403, $this->ajaxCode($task + ['client' => 'site']));
        $this->assertSame(400, $this->ajaxCode($task));
    }

    #[TestDox('Scanning and writing tasks refuse GET with 405, before doing anything')]
    public function testPostOnlyTasks(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        foreach (['zerodates', 'unused', 'ignore', 'reopen'] as $task) {
            $this->assertSame(405, $this->ajaxCode(['input' => ['task' => $task]]), $task);
        }

        $this->assertSame(403, $this->ajaxCode(['input' => ['task' => 'ignore'], 'admin' => false]));
    }

    #[TestDox('The schema result also carries plain text, escaped only in text')]
    public function testSchemaLabelIsPlain(): void
    {
        $language = $this->createStub(Language::class);
        $language->method('_')->willReturn("<b>'%d'");
        $language->method('getPluralSuffixes')->willReturn(['MORE']);
        $language->method('hasKey')->willReturn(false);
        Factory::$language = $language;

        $summary = Database::schemaSummary([]);

        $this->assertSame("<b>'0'", $summary['label']);
        $this->assertSame('&lt;b&gt;&#039;0&#039;', $summary['text']);
    }

    /**
     * Request data for detailsData().
     *
     * @param   array  $groups   The result of UnusedTables::groups()
     * @param   array  $ignores  Ignore entries
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function detailsInput(array $groups, array $ignores = []): array
    {
        return ['groups' => $groups, 'findings' => [], 'ignores' => new IgnoreList($ignores)];
    }

    #[TestDox('Details pick the group by prefix, case-insensitively, or every group with all')]
    public function testDetailsGroups(): void
    {
        $ignores = new IgnoreList([]);
        $groups  = UnusedTables::groups($this->tables(['hc_orphan', 'kiufs_content', 'nounderscore']), [], 'hc_', $ignores);
        $data    = $this->detailsInput($groups);
        $prefix  = static fn (array $out): array => array_column($out['groups'], 'prefix');

        $this->assertSame(['hc_'], $prefix(Database::detailsData('unused', $data, 'HC_', false)));
        $this->assertSame(['kiufs_'], $prefix(Database::detailsData('unused', $data, 'kiufs_', false)));
        $this->assertSame([''], $prefix(Database::detailsData('unused', $data, '', false)));
        $this->assertSame([], $prefix(Database::detailsData('unused', $data, 'nope_', false)));
        $this->assertSame(['hc_', '', 'kiufs_'], $prefix(Database::detailsData('unused', $data, 'ignored', true)));
    }

    #[TestDox('With an empty site prefix, the empty key is the own group')]
    public function testDetailsEmptySitePrefix(): void
    {
        $groups = UnusedTables::groups($this->tables(['orphan', 'kiufs_content']), [], '', new IgnoreList([]));
        $out    = Database::detailsData('unused', $this->detailsInput($groups), '', false);

        $this->assertCount(1, $out['groups']);
        $this->assertTrue($out['groups'][0]['own']);
        $this->assertSame(['kiufs_content', 'orphan'], array_column($out['groups'][0]['tables'], 'name'));
    }

    #[TestDox('Details mark table states, keep table entries under Ignore all, and list open rows first')]
    public function testDetailsStates(): void
    {
        $entries = [
            ['kind' => 'table', 'name' => 'hc_a'],
            ['kind' => 'prefix', 'name' => 'kiufs_'],
            ['kind' => 'table', 'name' => 'kiufs_b'],
        ];
        $ignores = new IgnoreList($entries);
        $groups  = UnusedTables::groups($this->tables(['hc_a', 'hc_b', 'kiufs_a', 'kiufs_b', 'old_x']), [], 'hc_', $ignores);
        $out     = Database::detailsData('unused', $this->detailsInput($groups, $entries), '', true);
        $states  = array_map(
            static fn (array $group): array => array_column($group['tables'], 'state', 'name'),
            array_column($out['groups'], null, 'prefix')
        );

        $this->assertSame(['hc_', 'old_', 'kiufs_'], array_column($out['groups'], 'prefix'));
        $this->assertSame(['hc_b' => 'open', 'hc_a' => 'ignored'], $states['hc_']);
        $this->assertSame(['kiufs_a' => 'prefix', 'kiufs_b' => 'ignored'], $states['kiufs_']);
        $this->assertSame(['old_x' => 'open'], $states['old_']);
    }

    #[TestDox('Zero-date details pick the table, case-insensitively, open columns first')]
    public function testDetailsZeroDates(): void
    {
        $ignores  = new IgnoreList([['kind' => 'column', 'name' => 'hc_a.created']]);
        $findings = ZeroDateScanner::findings($this->zeroState(['hc_a' => ['created', 'modified'], 'hc_b' => ['x']]), $ignores);
        $data     = ['groups' => [], 'findings' => $findings, 'ignores' => $ignores];

        $one = Database::detailsData('zerodates', $data, 'HC_A', false)['findings'];
        $this->assertSame(['modified', 'created'], array_column($one, 'column'));
        $this->assertSame(['open', 'ignored'], array_column($one, 'state'));
        $this->assertCount(3, Database::detailsData('zerodates', $data, '', true)['findings']);
        $this->assertSame([], Database::detailsData('zerodates', $data, '', false)['findings']);
    }

    #[TestDox('The Details button carries the finding kind and key')]
    public function testDetailsButton(): void
    {
        $columns = array_column(Database::columns(['finding' => 'F', 'where' => 'W', 'count' => 'C', 'details' => 'Details']), null, 'key');
        $html    = $columns['details']['renderer']('', ['kind' => 'unused', 'key' => 'kiufs_"x']);

        $this->assertStringContainsString('class="btn btn-sm btn-outline-secondary plg-hc-db-details"', $html);
        $this->assertStringContainsString('data-hc-db-kind="unused"', $html);
        $this->assertStringContainsString('data-hc-db-key="kiufs_&quot;x"', $html);
        $this->assertSame('badge', $columns['count']['type']);
        $this->assertSame('warning', $columns['count']['badgeClass']);
    }

    #[TestDox('A zero-date table that could not be checked is named on the tile, which is never green')]
    public function testZeroDatesError(): void
    {
        $state            = $this->zeroState([]);
        $state['pass']    = '2026-10-17 10:00:00';
        $state['tables']  = ['hc_a' => [], 'hc_b' => []];
        $state['cursor']  = 1;
        $state['error']   = ['table' => 'hc_<b>', 'at' => '2026-10-17 10:00:00'];
        $state['updated'] = '2026-10-16 10:00:00';

        $zero = $this->icons($this->plugin(['check_unused' => 0], ['zerodates' => $state]))['plg_healthcheck_database_zerodates'];

        $this->assertSame('warning', $zero['status']);
        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_ZERODATES_ERROR', $zero['text']);
        $this->assertStringNotContainsString('PLG_HEALTHCHECK_DATABASE_ZERODATES_NONE', $zero['text']);

        // Never checked before: still not left without a status.
        $state['updated'] = null;
        $zero             = $this->icons($this->plugin(['check_unused' => 0], ['zerodates' => $state]))['plg_healthcheck_database_zerodates'];

        $this->assertSame('warning', $zero['status']);
        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_ZERODATES_ERROR', $zero['text']);
    }

    #[TestDox('Tables too large to check in one request are counted on the tile')]
    public function testZeroDatesSkipped(): void
    {
        $state             = $this->zeroState([]);
        $state['pass']     = '2026-10-17 10:00:00';
        $state['updated']  = '2026-10-17 10:05:00';
        $state['complete'] = true;
        $state['skipped']  = ['hc_big' => ['name' => 'hc_big', 'at' => '2026-10-17 10:01:00']];

        $zero = $this->icons($this->plugin(['check_unused' => 0], ['zerodates' => $state]))['plg_healthcheck_database_zerodates'];

        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_ZERODATES_SKIPPED', $zero['text']);
        $this->assertStringNotContainsString('PLG_HEALTHCHECK_DATABASE_ZERODATES_NONE', $zero['text']);

        // A value cached by an older version (bare name) still counts.
        $state['skipped'] = ['hc_big' => 'hc_big'];
        $zero             = $this->icons($this->plugin(['check_unused' => 0], ['zerodates' => $state]))['plg_healthcheck_database_zerodates'];

        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_ZERODATES_SKIPPED', $zero['text']);
    }

    #[TestDox('Tables not checked because of their name get their own tile line and Details note, status unchanged')]
    public function testZeroDatesSkippedForName(): void
    {
        $state             = $this->zeroState([]);
        $state['pass']     = '2026-10-17 10:00:00';
        $state['updated']  = '2026-10-17 10:05:00';
        $state['complete'] = true;

        $clean = $this->icons($this->plugin(['check_unused' => 0], ['zerodates' => $state]))['plg_healthcheck_database_zerodates'];

        $state['skipped'] = ['hc_old#__users' => ['name' => 'hc_old#__users', 'at' => '2026-10-17 10:00:00', 'reason' => 'name']];

        $zero = $this->icons($this->plugin(['check_unused' => 0], ['zerodates' => $state]))['plg_healthcheck_database_zerodates'];

        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_ZERODATES_NAME_SKIPPED', $zero['text']);
        $this->assertStringNotContainsString('PLG_HEALTHCHECK_DATABASE_ZERODATES_SKIPPED', $zero['text']);
        $this->assertStringNotContainsString('PLG_HEALTHCHECK_DATABASE_ZERODATES_NONE', $zero['text']);
        $this->assertSame($clean['status'] ?? null, $zero['status'] ?? null, 'R15: text only');

        $ignores = new IgnoreList([]);
        $data    = ['groups' => [], 'findings' => [], 'ignores' => $ignores, 'state' => $state];
        $out     = Database::detailsData('zerodates', $data, '', true);

        $this->assertSame([], $out['skipped']);
        $this->assertSame(['hc_old#__users'], $out['skippedName']);

        $html = Database::renderDetails(JPATH_ROOT . '/plugins/healthcheck/database/tmpl/details.php', $out);

        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_DETAILS_NAME_SKIPPED', $html);
        $this->assertStringContainsString('<code>hc_old#__users</code>', $html);
        $this->assertStringNotContainsString('PLG_HEALTHCHECK_DATABASE_DETAILS_SKIPPED', $html);
        $this->assertStringNotContainsString('PLG_HEALTHCHECK_DATABASE_DETAILS_NONE', $html);

        $strings = parse_ini_file(JPATH_ROOT . '/administrator/language/en-GB/plg_healthcheck_database.ini');

        foreach (['PLG_HEALTHCHECK_DATABASE_DETAILS_NAME_SKIPPED', 'PLG_HEALTHCHECK_DATABASE_ZERODATES_NAME_SKIPPED', 'PLG_HEALTHCHECK_DATABASE_ZERODATES_NAME_SKIPPED_1'] as $key) {
            $this->assertArrayHasKey($key, $strings);
        }

        $this->assertStringContainsString('#__', $strings['PLG_HEALTHCHECK_DATABASE_DETAILS_NAME_SKIPPED']);
    }

    #[TestDox('The zero-date progress result reports an error, which is never complete')]
    public function testProgressResultError(): void
    {
        $state           = ZeroDateScanner::emptyState();
        $state['tables'] = ['hc_a' => [], 'hc_b' => []];
        $state['cursor'] = 1;

        $this->assertSame(['complete' => false, 'error' => false, 'checked' => 1, 'total' => 2, 'busy' => false], Database::progressResult($state));

        $state['error'] = ['table' => 'hc_b', 'at' => '2026-10-17 10:00:00'];

        $this->assertSame(['complete' => false, 'error' => true, 'checked' => 1, 'total' => 2, 'busy' => false], Database::progressResult($state));
        $this->assertTrue(Database::progressResult($state, true)['busy']);
    }

    #[TestDox('Zero-date details carry the skipped tables and the table that could not be checked')]
    public function testDetailsZeroDateNotes(): void
    {
        $state            = $this->zeroState(['hc_a' => ['created']]);
        $state['skipped'] = ['hc_big' => ['name' => 'HC_Big', 'at' => '2026-10-17 10:00:00'], 'hc_old' => 'HC_Old'];
        $state['error']   = ['table' => 'hc_b', 'at' => '2026-10-17 10:00:00'];
        $ignores          = new IgnoreList([]);
        $data             = ['groups' => [], 'findings' => ZeroDateScanner::findings($state, $ignores), 'ignores' => $ignores, 'state' => $state];

        $this->assertSame(['HC_Big'], Database::detailsData('zerodates', $data, 'hc_big', false)['skipped']);

        $out = Database::detailsData('zerodates', $data, '', true);

        $this->assertSame(['HC_Big', 'HC_Old'], $out['skipped'], 'a bare name cached by an older version is still listed');
        $this->assertSame('hc_b', $out['error']);

        $html = Database::renderDetails(JPATH_ROOT . '/plugins/healthcheck/database/tmpl/details.php', $out);

        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_DETAILS_SKIPPED', $html);
        $this->assertStringContainsString('<code>HC_Big</code>', $html);
        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_ZERODATES_ERROR', $html);
        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_DETAILS_NEXT_ZERODATES', $html);
    }

    #[TestDox('The unused-table details end with the next step')]
    public function testDetailsUnusedNextStep(): void
    {
        $groups = UnusedTables::groups($this->tables(['hc_orphan']), [], 'hc_', new IgnoreList([]));
        $out    = Database::detailsData('unused', $this->detailsInput($groups), 'hc_', false);
        $html   = Database::renderDetails(JPATH_ROOT . '/plugins/healthcheck/database/tmpl/details.php', $out);

        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_DETAILS_NEXT_UNUSED', $html);
        $this->assertStringNotContainsString('PLG_HEALTHCHECK_DATABASE_DETAILS_NEXT_ZERODATES', $html);
    }

    #[TestDox('The next-step sentences are plain advice without em-dashes')]
    public function testNextStepStrings(): void
    {
        $strings = parse_ini_file(JPATH_ROOT . '/administrator/language/en-GB/plg_healthcheck_database.ini');

        foreach (['PLG_HEALTHCHECK_DATABASE_DETAILS_NEXT_UNUSED', 'PLG_HEALTHCHECK_DATABASE_DETAILS_NEXT_ZERODATES'] as $key) {
            $this->assertArrayHasKey($key, $strings);
            $this->assertStringContainsString('Back up', $strings[$key]);
            $this->assertStringNotContainsString("\u{2014}", $strings[$key]);
        }

        $this->assertStringStartsWith('Last checked: ', $strings['PLG_HEALTHCHECK_DATABASE_ZERODATES_CHECKED']);
    }

    #[TestDox('A failing schema check says unavailable with a warning, never a healthy 0')]
    public function testSchemaFailure(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $result = $this->ajaxResult(['input' => ['task' => 'schema'], 'bootFails' => true]);

        $this->assertSame(0, $result['amount']);
        $this->assertSame('warning', $result['status']);
        $this->assertSame('PLG_HEALTHCHECK_DATABASE_UNAVAILABLE', $result['label']);
        $this->assertSame('PLG_HEALTHCHECK_DATABASE_UNAVAILABLE', $result['text']);
    }

    #[TestDox('The scanning tasks close the session before they scan')]
    public function testScanTasksCloseSession(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        foreach (['zerodates', 'unused'] as $task) {
            $session = $this->createMock(SessionInterface::class);
            $session->expects($this->once())->method('close');

            // The stub database fails the scan; the session must be closed by then.
            $this->assertSame(0, $this->ajaxCode(['input' => ['task' => $task], 'session' => $session]), $task);
        }
    }

    #[TestDox('A stale unused-table cache is shown as is and refreshed in the background')]
    public function testStaleUnusedRefreshesInBackground(): void
    {
        $unused   = ['updated' => '2000-01-01 00:00:00', 'tables' => $this->tables(['hc_orphan']), 'accounted' => []];
        $fresh    = ['updated' => (new Date())->toSql()] + $unused;
        $document = $this->document();
        $this->icons($this->plugin(['check_zerodates' => 0], ['unused' => $fresh], ['document' => $document]));

        // A fresh list only reads the ignore entries; a stale one must not query more (no inline rebuild).
        $freshQueries  = $this->queries;
        $this->queries = 0;

        $this->assertFalse($document->getScriptOptions('plg_healthcheck_database')['unusedDue']);

        $document = $this->document();
        $icons    = $this->icons($this->plugin(['check_zerodates' => 0], ['unused' => $unused], ['document' => $document]));

        $this->assertSame(1, $icons['plg_healthcheck_database_unused']['amount']);
        $this->assertSame($freshQueries, $this->queries, 'no synchronous rebuild');
        $this->assertTrue($document->getScriptOptions('plg_healthcheck_database')['unusedDue']);
    }

    #[TestDox('Without zero dates, Check now says it re-runs the unused-table check only')]
    public function testCheckNowTextWithoutZeroDates(): void
    {
        $unused = ['updated' => (new Date())->toSql(), 'tables' => $this->tables(['hc_orphan']), 'accounted' => []];

        $pg = $this->icons($this->plugin([], ['unused' => $unused], ['server' => 'postgresql']));
        $this->assertSame('PLG_HEALTHCHECK_DATABASE_CHECKNOW_TEXT_UNUSED', $pg['plg_healthcheck_database_checknow']['text']);

        $my = $this->icons($this->plugin([], ['unused' => $unused]));
        $this->assertSame('PLG_HEALTHCHECK_DATABASE_CHECKNOW_TEXT', $my['plg_healthcheck_database_checknow']['text']);
    }

    /**
     * A zero-date pass on hc_a, hc_b with the cursor on hc_b and its marker set $age seconds ago.
     *
     * @param   float  $age  Seconds since the marker was set
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function markedState(float $age): array
    {
        $state               = ZeroDateScanner::emptyState();
        $state['pass']       = '2026-10-17 10:00:00';
        $state['tables']     = [
            'hc_a' => ['name' => 'hc_a', 'columns' => [['column' => 'created', 'type' => 'datetime']]],
            'hc_b' => ['name' => 'hc_b', 'columns' => [['column' => 'created', 'type' => 'datetime']]],
        ];
        $state['cursor']     = 1;
        $state['inprogress'] = ['key' => 'hc_b', 'at' => microtime(true) - $age];

        return $state;
    }

    #[TestDox('A zero-date batch while another request counts the cursor table reports busy and stores nothing')]
    public function testZeroDatesBusy(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        foreach (['0', '1'] as $restart) {
            $_POST         = ['restart' => $restart];
            $this->stores  = 0;
            $this->queries = 0;

            $result = $this->ajaxResult(['input' => ['task' => 'zerodates'], 'cached' => ['zerodates' => $this->markedState(1)]]);

            $this->assertSame(['complete' => false, 'error' => false, 'checked' => 1, 'total' => 2, 'busy' => true], $result, 'restart=' . $restart);
            $this->assertSame(0, $this->stores, 'the other request\'s state is not overwritten');
            $this->assertSame(0, $this->queries, 'nothing is scanned');
        }
    }

    #[TestDox('A zero-date marker older than the dead-after time is a dead request: the batch runs and stores')]
    public function testZeroDatesDeadMarkerRuns(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = ['restart' => '0'];

        $result = $this->ajaxResult(['input' => ['task' => 'zerodates'], 'cached' => ['zerodates' => $this->markedState(100000)]]);

        $this->assertFalse($result['busy']);
        $this->assertSame(2, $result['checked'], 'the dead table is skipped');
        $this->assertTrue($result['complete']);
        $this->assertGreaterThan(0, $this->stores);
    }

    #[TestDox('A zero-date marker cached by an older version (bare key) is not taken as busy')]
    public function testZeroDatesOldMarkerShape(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST                     = ['restart' => '0'];
        $state                     = $this->markedState(0);
        $state['inprogress']       = 'hc_b';
        $state['skipped']          = ['hc_b' => 'hc_b'];

        $result = $this->ajaxResult(['input' => ['task' => 'zerodates'], 'cached' => ['zerodates' => $state]]);

        $this->assertIsArray($result);
        $this->assertFalse($result['busy']);
        $this->assertGreaterThan(0, $this->stores);
    }
}
