<?php

/**
 * @package     Joomla.IntegrationTest
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Integration\Plugin\Healthcheck\Database\Extension;

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Cache\CacheControllerFactory;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseFactory;
use Joomla\Filesystem\Folder;
use Joomla\Input\Input;
use Joomla\Plugin\Healthcheck\Database\Catalog\CatalogFactory;
use Joomla\Plugin\Healthcheck\Database\Extension\Database;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreList;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreRepository;
use Joomla\Plugin\Healthcheck\Database\Ownership\TableOwnership;
use Joomla\Plugin\Healthcheck\Database\Scan\ResultStore;
use Joomla\Plugin\Healthcheck\Database\Scan\ZeroDateScanner;
use Joomla\Session\SessionInterface;
use Joomla\Tests\Integration\DBTestInterface;
use Joomla\Tests\Integration\DBTestTrait;
use Joomla\Tests\Integration\IntegrationTestCase;

/**
 * Test class for the Database health check Ajax tasks (runs on MySQL/MariaDB and PostgreSQL).
 *
 * The plugin runs on its own connection whose prefix is the test prefix plus `hcdb_ajax_`, so the site's
 * own tables are only the ones this test creates: `#__extensions` (empty), the ignore table and the planted tables.
 *
 * @package     Joomla.IntegrationTest
 * @subpackage  Healthcheck.Database
 *
 * @since       __DEPLOY_VERSION__
 */
class AjaxTest extends IntegrationTestCase implements DBTestInterface
{
    use DBTestTrait;

    /**
     * @var    DatabaseDriver  The plugin's connection, with the test prefix
     * @since  __DEPLOY_VERSION__
     */
    private DatabaseDriver $db;

    /**
     * @var    string  Cache folder for this test
     * @since  __DEPLOY_VERSION__
     */
    private string $cachePath;

    /**
     * @var    array  Superglobals before the test
     * @since  __DEPLOY_VERSION__
     */
    private array $globals = [];

    public function getSchemasToLoad(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->globals   = [$_GET, $_POST, $_SERVER['REQUEST_METHOD'] ?? null];
        $this->cachePath = sys_get_temp_dir() . '/hcdb-ajax-' . bin2hex(random_bytes(4));
        mkdir($this->cachePath);

        $engine   = getenv('JTEST_DB_ENGINE') ?: JTEST_DB_ENGINE;
        $this->db = (new DatabaseFactory())->getDriver($engine, [
            'database' => getenv('JTEST_DB_NAME') ?: JTEST_DB_NAME,
            'host'     => getenv('JTEST_DB_HOST') ?: JTEST_DB_HOST,
            'user'     => getenv('JTEST_DB_USER') ?: JTEST_DB_USER,
            'password' => getenv('JTEST_DB_PASSWORD') ?: JTEST_DB_PASSWORD,
            'prefix'   => $this->getDBDriver()->getPrefix() . 'hcdb_ajax_',
        ]);

        $this->dropOwnTables();

        $sql = file_get_contents(JPATH_ROOT . '/tests/Integration/datasets/' . strtolower($engine) . '/healthcheck_database.sql');

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->exec($statement);
        }

        $this->exec('CREATE TABLE ' . $this->db->quoteName('#__extensions') . ' ('
            . implode(', ', array_map(fn ($column) => $this->db->quoteName($column) . ' VARCHAR(100) NULL', ['type', 'element', 'folder', 'client_id']))
            . ')');
        $this->exec('CREATE TABLE ' . $this->db->quoteName('#__orphan') . ' (id INT NOT NULL)');
        $this->exec('CREATE TABLE ' . $this->db->quoteName('#__aaa_orphan') . ' (id INT NOT NULL)');
        $this->exec('CREATE TABLE ' . $this->db->quoteName($this->evilTable()) . ' (id INT NOT NULL)');
        $this->exec('CREATE TABLE ' . $this->db->quoteName($this->otherTable()) . ' (id INT NOT NULL)');
    }

    protected function tearDown(): void
    {
        $this->dropOwnTables();
        $this->exec('DROP TABLE IF EXISTS ' . $this->db->quoteName($this->otherTable()));
        $this->db->disconnect();

        Folder::delete($this->cachePath);

        [$_GET, $_POST, $method] = $this->globals;

        if ($method === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $method;
        }

        Factory::$application = null;
        Factory::$language    = null;

        parent::tearDown();
    }

    private function exec(string $sql): void
    {
        $this->db->setQuery($sql)->execute();
    }

    private function isMysql(): bool
    {
        return $this->db->getServerType() === 'mysql';
    }

    /**
     * A site table whose name holds markup.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function evilTable(): string
    {
        return $this->db->getPrefix() . '<b>';
    }

    /**
     * A table outside the plugin's prefix, so in the other-prefix group of the test prefix (`jos84_`).
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function otherTable(): string
    {
        return $this->getDBDriver()->getPrefix() . 'hcdb_other';
    }

    private function dropOwnTables(): void
    {
        $prefix = strtolower($this->db->getPrefix());

        foreach (CatalogFactory::for($this->db)->tables() as $table) {
            if (!str_starts_with(strtolower($table['name']), $prefix)) {
                continue;
            }

            if ($this->isMysql()) {
                // Quoted as one part and sent raw: a test table name may hold a dot or a literal #__.
                $this->raw('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table['name']) . '`');
            } else {
                $this->exec('DROP TABLE IF EXISTS ' . $this->db->quoteName($table['name']));
            }
        }
    }

    /**
     * Run a statement on the MySQL connection as is, without the driver's #__ replacement.
     *
     * @param   string  $sql  The statement
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function raw(string $sql): void
    {
        $this->db->connect();

        $connection = $this->db->getConnection();

        if ($connection instanceof \mysqli) {
            $connection->query($sql);
        } else {
            $connection->exec($sql);
        }
    }

    /**
     * Dispatch one Ajax request to a plugin built like services/provider.php does.
     *
     * @param   string  $method  GET or POST
     * @param   array   $get     Query parameters (the form token is added)
     * @param   array   $post    Body parameters
     * @param   array   $opts    admin (core.admin), params (plugin params)
     *
     * @return  array|integer  The task result, or the exception code
     *
     * @since   __DEPLOY_VERSION__
     */
    private function ajax(string $method, array $get, array $post = [], array $opts = []): array|int
    {
        $language = $this->createStub(Language::class);
        $language->method('_')->willReturnArgument(0);
        $language->method('getPluralSuffixes')->willReturn(['MORE']);
        $language->method('hasKey')->willReturn(false);
        $language->method('getTag')->willReturn('en-GB');
        Factory::$language = $language;

        $user = $this->createStub(User::class);
        $user->method('authorise')->willReturn($opts['admin'] ?? true);
        $user->method('getTimezone')->willReturn(new \DateTimeZone('UTC'));
        $user->id = 42;

        $session = $this->createStub(SessionInterface::class);
        $session->method('get')->willReturn($user);
        $session->method('getToken')->willReturn('t');
        $session->method('isNew')->willReturn(false);

        $config = ['secret' => 's', 'cache_handler' => 'file', 'cache_path' => $this->cachePath, 'caching' => 0, 'cachetime' => 15, 'offset' => 'UTC'];

        $app = $this->createStub(AdministratorApplication::class);
        $app->method('getIdentity')->willReturn($user);
        $app->method('getSession')->willReturn($session);
        $app->method('getLanguage')->willReturn($language);
        $app->method('getTemplate')->willReturn((object) ['template' => 'atum', 'parent' => '']);
        $app->method('get')->willReturnCallback(static fn ($key, $default = null) => $config[$key] ?? $default);
        $app->method('isClient')->willReturnCallback(static fn ($client) => $client === 'administrator');
        Factory::$application = $app;

        $_GET                      = $get + [Session::getFormToken() => '1'];
        $_POST                     = $post;
        $_SERVER['REQUEST_METHOD'] = $method;
        $app->method('getInput')->willReturn(new Input(array_merge($_GET, $_POST)));

        $plugin = new Database(
            ['name' => 'database', 'type' => 'healthcheck', 'params' => json_encode(($opts['params'] ?? []) + ['context' => 'general'])],
            new IgnoreRepository($this->db),
            $this->store(),
            new TableOwnership(JPATH_ROOT, JPATH_ADMINISTRATOR)
        );
        $plugin->setApplication($app);
        $plugin->setDatabase($this->db);

        $event = new AjaxEvent('onAjaxDatabase', ['subject' => $app]);

        try {
            $plugin->onAjaxDatabase($event);
        } catch (\RuntimeException $e) {
            return $e->getCode();
        }

        return $event->getArgument('result')[0];
    }

    private function store(): ResultStore
    {
        return new ResultStore(new CacheControllerFactory());
    }

    private function ignored(): array
    {
        return array_map(static fn ($row) => [$row['kind'], $row['name'], $row['created_by']], (new IgnoreRepository($this->db))->all());
    }

    public function testIgnoreReopenRoundTrip(): void
    {
        $table = strtoupper($this->db->getPrefix()) . 'ORPHAN';

        $this->assertSame(['ok' => true], $this->ajax('POST', ['task' => 'ignore'], ['kind' => 'table', 'name' => $table]));
        $this->assertSame(['ok' => true], $this->ajax('POST', ['task' => 'ignore'], ['kind' => 'column', 'name' => 'x_content.created']));
        $this->assertSame(['ok' => true], $this->ajax('POST', ['task' => 'ignore'], ['kind' => 'prefix', 'name' => 'kiufs_']));
        $this->assertSame(['ok' => false], $this->ajax('POST', ['task' => 'ignore'], ['kind' => 'table', 'name' => "x\x00y"]));

        $this->assertSame([
            ['table', strtolower($table), 42],
            ['column', 'x_content.created', 42],
            ['prefix', 'kiufs_', 42],
        ], $this->ignored());

        $this->assertSame(['ok' => true], $this->ajax('POST', ['task' => 'reopen'], ['kind' => 'table', 'name' => $table]));
        $this->assertSame(['ok' => true], $this->ajax('POST', ['task' => 'reopen'], ['kind' => 'prefix', 'name' => 'KIUFS_']));
        $this->assertSame(['ok' => false], $this->ajax('POST', ['task' => 'reopen'], ['kind' => 'prefix', 'name' => 'kiufs_']));

        $this->assertSame([['column', 'x_content.created', 42]], $this->ignored());
    }

    public function testRejectsOwnPrefix(): void
    {
        $this->assertSame(400, $this->ajax('POST', ['task' => 'ignore'], ['kind' => 'prefix', 'name' => strtoupper($this->db->getPrefix())]));
        $this->assertSame(400, $this->ajax('POST', ['task' => 'ignore'], ['kind' => 'drop', 'name' => 'kiufs_']));
        $this->assertSame(400, $this->ajax('POST', ['task' => 'reopen'], ['kind' => '', 'name' => 'kiufs_']));
        $this->assertSame([], $this->ignored());
    }

    public function testWritesNeedPost(): void
    {
        foreach (['ignore', 'reopen', 'zerodates', 'unused'] as $task) {
            $this->assertSame(405, $this->ajax('GET', ['task' => $task, 'kind' => 'table', 'name' => 'kiufs_x']), $task);
        }

        $this->assertSame([], $this->ignored());
        $this->assertNull($this->store()->get('unused'));
        $this->assertNull($this->store()->get('zerodates'));
    }

    public function testNeedsCoreAdmin(): void
    {
        foreach (['ignore', 'reopen', 'zerodates', 'unused', 'details', 'schema'] as $task) {
            $this->assertSame(403, $this->ajax('POST', ['task' => $task], ['kind' => 'table', 'name' => 'kiufs_x'], ['admin' => false]), $task);
        }

        $this->assertSame([], $this->ignored());
    }

    public function testZeroDatesBatches(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('Zero dates exist on MySQL/MariaDB only.');
        }

        $mode = (string) $this->db->setQuery('SELECT @@SESSION.sql_mode')->loadResult();
        $this->exec("SET SESSION sql_mode = ''");

        try {
            foreach (['#__zd_a', '#__zd_b'] as $table) {
                $this->exec('CREATE TABLE ' . $this->db->quoteName($table) . ' (created DATETIME NULL)');
                $this->exec('INSERT INTO ' . $this->db->quoteName($table) . " (created) VALUES ('0000-00-00 00:00:00'), (NULL)");
            }
        } finally {
            $this->exec('SET SESSION sql_mode = ' . $this->db->quote($mode));
        }

        // Date tables with the plugin's prefix: the ignore table, zd_a and zd_b.
        $budget = ['params' => ['time_budget' => 0]];
        $post   = fn (string $restart) => $this->ajax('POST', ['task' => 'zerodates'], ['restart' => $restart], $budget);

        $this->assertSame(['complete' => false, 'error' => false, 'checked' => 1, 'total' => 3, 'busy' => false], $post('0'));
        $this->assertSame(['complete' => false, 'error' => false, 'checked' => 2, 'total' => 3, 'busy' => false], $post('0'));
        $this->assertSame(['complete' => true, 'error' => false, 'checked' => 3, 'total' => 3, 'busy' => false], $post('0'));

        $findings = ZeroDateScanner::findings($this->store()->get('zerodates'), new IgnoreList([]));
        $prefix   = $this->db->getPrefix();
        $this->assertSame([$prefix . 'zd_a', $prefix . 'zd_b'], array_column($findings, 'table'));
        $this->assertSame([1, 1], array_column($findings, 'zero'));

        // A fresh complete pass is not scanned again; restart=1 begins a new one.
        $this->assertSame(['complete' => true, 'error' => false, 'checked' => 3, 'total' => 3, 'busy' => false], $post('0'));
        $this->assertSame(['complete' => false, 'error' => false, 'checked' => 1, 'total' => 3, 'busy' => false], $post('1'));

        // A stale complete pass starts over without restart.
        $state             = $this->store()->get('zerodates');
        $state['cursor']   = 3;
        $state['complete'] = true;
        $state['updated']  = '2000-01-01 00:00:00';
        $this->store()->put('zerodates', $state);
        $this->assertSame(['complete' => false, 'error' => false, 'checked' => 1, 'total' => 3, 'busy' => false], $post('0'));

        $html = $this->ajax('GET', ['task' => 'details', 'kind' => 'zerodates', 'key' => strtoupper($prefix) . 'ZD_B'])['html'];
        $this->assertStringContainsString($prefix . 'zd_b', $html);
        $this->assertStringNotContainsString($prefix . 'zd_a', $html);
        $this->assertStringContainsString('data-hc-db-kind="column" data-hc-db-name="' . $prefix . 'zd_b.created"', $html);
        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_DETAILS_READONLY', $html);

        $all = $this->ajax('GET', ['task' => 'details', 'kind' => 'zerodates', 'key' => 'ignored', 'all' => '1'])['html'];
        $this->assertStringContainsString($prefix . 'zd_a', $all);
        $this->assertStringContainsString($prefix . 'zd_b', $all);
    }

    public function testZeroDatesSkipsTableARequestDiedOn(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('Zero dates exist on MySQL/MariaDB only.');
        }

        foreach (['#__zd_a', '#__zd_b'] as $table) {
            $this->exec('CREATE TABLE ' . $this->db->quoteName($table) . ' (created DATETIME NULL)');
        }

        $budget = ['params' => ['time_budget' => 0]];
        $post   = fn () => $this->ajax('POST', ['task' => 'zerodates'], ['restart' => '0'], $budget);

        // Date tables with the plugin's prefix: the ignore table, zd_a and zd_b.
        $this->assertSame(['complete' => false, 'error' => false, 'checked' => 1, 'total' => 3, 'busy' => false], $post());

        // The request for the second table died while counting it: its marker is still stored.
        $state = $this->store()->get('zerodates');
        $key   = array_keys($state['tables'])[1];
        $this->assertNull($state['inprogress']);
        $state['inprogress'] = ['key' => $key, 'at' => microtime(true) - 100000];
        $this->store()->put('zerodates', $state);

        $this->assertSame(['complete' => true, 'error' => false, 'checked' => 3, 'total' => 3, 'busy' => false], $post());

        $state = $this->store()->get('zerodates');
        $this->assertSame([$key], array_keys($state['skipped']));
        $this->assertSame($this->db->getPrefix() . 'zd_a', $state['skipped'][$key]['name']);
        $this->assertNull($state['inprogress']);

        // Check now within the refresh hours keeps it skipped: the new pass completes without counting it.
        $restart = fn () => $this->ajax('POST', ['task' => 'zerodates'], ['restart' => '1'], ['params' => ['time_budget' => 20]]);
        $this->assertSame(['complete' => true, 'error' => false, 'checked' => 3, 'total' => 3, 'busy' => false], $restart());
        $this->assertSame([$key], array_keys($this->store()->get('zerodates')['skipped']));

        // A marker younger than the dead-after time: another request is counting, so the batch is busy and
        // nothing is stored.
        $state               = $this->store()->get('zerodates');
        $state['cursor']     = 1;
        $state['complete']   = false;
        $state['inprogress'] = ['key' => $key, 'at' => microtime(true)];
        $this->store()->put('zerodates', $state);

        $this->assertSame(['complete' => false, 'error' => false, 'checked' => 1, 'total' => 3, 'busy' => true], $post());
        $this->assertSame($state, $this->store()->get('zerodates'));
    }

    public function testZeroDatesRestartRightAfterDeathSkipsTable(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('Zero dates exist on MySQL/MariaDB only.');
        }

        foreach (['#__zd_a', '#__zd_b'] as $table) {
            $this->exec('CREATE TABLE ' . $this->db->quoteName($table) . ' (created DATETIME NULL)');
        }

        $post = fn (string $restart, int $budget) => $this->ajax('POST', ['task' => 'zerodates'], ['restart' => $restart], ['params' => ['time_budget' => $budget]]);

        // Check now's first batch, then its request for the second table dies.
        $this->assertSame(['complete' => false, 'error' => false, 'checked' => 1, 'total' => 3, 'busy' => false], $post('1', 0));

        $state               = $this->store()->get('zerodates');
        $key                 = array_keys($state['tables'])[1];
        $state['inprogress'] = ['key' => $key, 'at' => microtime(true) - 100000];
        $this->store()->put('zerodates', $state);

        // Check now again, with no restart=0 batch in between: the table it died on is skipped.
        $this->assertSame(['complete' => true, 'error' => false, 'checked' => 3, 'total' => 3, 'busy' => false], $post('1', 20));

        $state = $this->store()->get('zerodates');
        $this->assertSame([$key], array_keys($state['skipped']));
        $this->assertSame($this->db->getPrefix() . 'zd_a', $state['skipped'][$key]['name']);
        $this->assertNull($state['inprogress']);
    }

    public function testZeroDatesDottedAndHashNames(): void
    {
        if (!$this->isMysql()) {
            $this->markTestSkipped('Zero dates exist on MySQL/MariaDB only.');
        }

        $prefix = $this->db->getPrefix();
        $dotted = $prefix . 'zd_dot.ted';
        $hash   = $prefix . 'zd_#__x';
        $colon  = $prefix . 'zd_log:2020';
        $mode   = (string) $this->db->setQuery('SELECT @@SESSION.sql_mode')->loadResult();

        $this->raw("SET SESSION sql_mode = ''");

        try {
            $this->raw('CREATE TABLE `' . $dotted . '` (`c.d` DATETIME NULL)');
            $this->raw('INSERT INTO `' . $dotted . "` (`c.d`) VALUES ('0000-00-00 00:00:00'), (NULL)");
            $this->raw('CREATE TABLE `' . $hash . '` (created DATETIME NULL)');
            $this->raw('INSERT INTO `' . $hash . "` (created) VALUES ('0000-00-00 00:00:00')");
            $this->raw('CREATE TABLE `' . $colon . '` (created DATETIME NULL)');
        } finally {
            $this->raw('SET SESSION sql_mode = ' . $this->db->quote($mode));
        }

        $result = $this->ajax('POST', ['task' => 'zerodates'], ['restart' => '1'], ['params' => ['time_budget' => 20]]);
        $state  = $this->store()->get('zerodates');

        $this->assertTrue($result['complete']);
        $this->assertNull($state['error']);

        // The dotted names are counted, not dropped as gone.
        $findings = ZeroDateScanner::findings($state, new IgnoreList([]));
        $this->assertSame([[$dotted, 'c.d', 1]], array_map(static fn ($f) => [$f['table'], $f['column'], $f['zero']], $findings));

        // The #__ name is never counted: listed as skipped for its name.
        $this->assertSame(['name' => $hash, 'at' => $state['skipped'][strtolower($hash)]['at'], 'reason' => 'name'], $state['skipped'][strtolower($hash)]);
        $this->assertArrayNotHasKey(strtolower($hash), $state['results']);

        // So is a name the statement layer would take for a :placeholder.
        $this->assertSame('name', $state['skipped'][strtolower($colon)]['reason']);

        $html = $this->ajax('GET', ['task' => 'details', 'kind' => 'zerodates', 'key' => 'all', 'all' => '1'])['html'];
        $this->assertStringContainsString('PLG_HEALTHCHECK_DATABASE_DETAILS_NAME_SKIPPED', $html);
        $this->assertStringContainsString('<code>' . htmlspecialchars($hash) . '</code>', $html);
    }

    public function testZeroDatesOffIsComplete(): void
    {
        $result = $this->ajax('POST', ['task' => 'zerodates'], [], ['params' => ['check_zerodates' => 0]]);

        $this->assertSame(['complete' => true, 'error' => false, 'checked' => 0, 'total' => 0, 'busy' => false], $result);
        $this->assertNull($this->store()->get('zerodates'));
    }

    public function testDetailsEscapes(): void
    {
        $prefix = $this->db->getPrefix();

        $this->assertSame(['ok' => true], $this->ajax('POST', ['task' => 'unused']));
        $this->assertSame(['ok' => true], $this->ajax('POST', ['task' => 'ignore'], ['kind' => 'table', 'name' => $prefix . 'aaa_orphan']));

        $html = $this->ajax('GET', ['task' => 'details', 'kind' => 'unused', 'key' => strtoupper($prefix)])['html'];

        $this->assertStringContainsString(htmlspecialchars($this->evilTable(), ENT_QUOTES, 'UTF-8'), $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString($prefix . 'orphan', $html);
        $this->assertStringNotContainsString($this->otherTable(), $html);

        // Open tables first, then the ignored one, which offers Reopen.
        $this->assertLessThan(strpos($html, $prefix . 'aaa_orphan'), strpos($html, '>' . $prefix . 'orphan<'));
        $this->assertStringContainsString('data-hc-db-action="reopen" data-hc-db-kind="table" data-hc-db-name="' . $prefix . 'aaa_orphan"', $html);
        $this->assertStringContainsString('data-hc-db-action="ignore" data-hc-db-kind="table" data-hc-db-name="' . $prefix . 'orphan"', $html);

        // Ignore all is never offered for the site's own prefix.
        $this->assertStringNotContainsString('data-hc-db-kind="prefix"', $html);

        $other      = $this->getDBDriver()->getPrefix();
        $otherGroup = $this->ajax('GET', ['task' => 'details', 'kind' => 'unused', 'key' => $other])['html'];
        $this->assertStringContainsString($this->otherTable(), $otherGroup);
        $this->assertStringContainsString('data-hc-db-action="ignore" data-hc-db-kind="prefix" data-hc-db-name="' . $other . '"', $otherGroup);

        $all = $this->ajax('GET', ['task' => 'details', 'kind' => 'unused', 'all' => '1'])['html'];
        $this->assertStringContainsString($prefix . 'orphan', $all);
        $this->assertStringContainsString($this->otherTable(), $all);

        $this->assertSame(400, $this->ajax('GET', ['task' => 'details', 'kind' => 'nope']));
    }
}
