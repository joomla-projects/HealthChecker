<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Frontenderrors\Extension;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;
use Joomla\Plugin\Healthcheck\Frontenderrors\Dashboard\Status;
use Joomla\Plugin\Healthcheck\Frontenderrors\Model\ErrorRepository;
use Joomla\Plugin\Healthcheck\Frontenderrors\Model\ScanPages;
use Joomla\Plugin\Healthcheck\Frontenderrors\Report\NoiseFilter;
use Joomla\Plugin\Healthcheck\Frontenderrors\Report\ReportNormaliser;
use Joomla\Plugin\Healthcheck\Frontenderrors\Settings\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Health Check plugin showing frontend JavaScript errors by page
 *
 * @since  __DEPLOY_VERSION__
 */
final class Frontenderrors extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * Pages listed in the table
     *
     * @since  __DEPLOY_VERSION__
     */
    public const TABLE_LIMIT = 10;

    /**
     * Characters of the top error message shown in the table
     *
     * @since  __DEPLOY_VERSION__
     */
    private const MESSAGE_LENGTH = 80;

    /**
     * Largest accepted scan results body, in bytes
     *
     * @since  __DEPLOY_VERSION__
     */
    public const MAX_BODY_BYTES = 8192;

    /**
     * Strings used by the admin script
     *
     * @since  __DEPLOY_VERSION__
     */
    private const SCRIPT_STRINGS = [
        'JCLOSE',
        'PLG_HEALTHCHECK_FRONTENDERRORS_REQUEST_FAILED',
        'PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_DONE',
        'PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_FRAMING_BLOCKED',
        'PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_PROGRESS',
        'PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_SKIPPED',
        'PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_STARTING',
    ];

    /**
     * Data for this request, loaded once
     *
     * @var    array|null
     * @since  __DEPLOY_VERSION__
     */
    private ?array $data = null;

    /**
     * Whether old data was purged during this request
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    private bool $purged = false;

    /**
     * Whether the admin script was added to the page
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    private bool $assetsLoaded = false;

    /**
     * Constructor
     *
     * @param   array            $config      The plugin configuration
     * @param   ErrorRepository  $repository  Reads and purges the collected errors
     * @param   ScanPages        $scanPages   Lists the menu items Scan now visits
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(array $config, private readonly ErrorRepository $repository, private readonly ScanPages $scanPages)
    {
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
            'onAjaxFrontenderrors'   => 'onAjaxFrontenderrors',
        ];
    }

    /**
     * Adds the summary and Scan now icons.
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

        $this->loadAssets();

        $data      = $this->data();
        $settings  = $data['settings'];
        $collector = PluginHelper::isEnabled('system', 'frontenderrors');
        $summary   = $data['summary'];
        $lines     = [];

        $lines[] = $summary['errors'] > 0
            ? Text::sprintf('PLG_HEALTHCHECK_FRONTENDERRORS_SUMMARY', $summary['errors'], $summary['pages'], $settings->periodDays)
            : Text::sprintf('PLG_HEALTHCHECK_FRONTENDERRORS_SUMMARY_NONE', $settings->periodDays);

        if ($summary['errors'] > 0 || $data['previous'] > 0) {
            $trend = Status::trend($summary['errors'], $data['previous']);

            $lines[] = Text::sprintf(
                'PLG_HEALTHCHECK_FRONTENDERRORS_TREND_' . strtoupper($trend['direction']),
                $trend['delta'],
                $settings->periodDays
            );
        }

        $lines[] = match ($data['state']) {
            'ongoing' => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_MODE_ONGOING'),
            'until'   => Text::sprintf('PLG_HEALTHCHECK_FRONTENDERRORS_MODE_UNTIL', (string) $settings->collectUntil),
            'ended'   => Text::sprintf('PLG_HEALTHCHECK_FRONTENDERRORS_MODE_ENDED', (string) $settings->collectUntil),
            default   => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_MODE_UNSET'),
        };

        if (!$collector) {
            $lines[] = Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLLECTOR_DISABLED');
        }

        if ($this->saturated()) {
            $lines[] = Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_SATURATED');
        }

        $section = Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_SECTION');

        $icon = [
            'id'      => 'plg_healthcheck_frontenderrors',
            'section' => $section,
            'name'    => 'PLG_HEALTHCHECK_FRONTENDERRORS_NAME',
            'icon'    => 'icon-code',
            'amount'  => $summary['errors'],
            'text'    => implode('<br>', array_map(static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8'), $lines)),
            'link'    => $this->editLink($collector ? 'healthcheck' : 'system'),
        ];

        $neutral = \in_array($data['state'], ['ended', 'unset'], true) && $summary['errors'] === 0;

        if (!$neutral) {
            $icon['status'] = $this->tileStatus();
        }

        $scan = [
            'id'      => 'plg_healthcheck_frontenderrors_scan',
            'section' => $section,
            'name'    => 'PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_NAME',
            'icon'    => 'icon-search',
            'text'    => htmlspecialchars(Text::_($collector ? 'PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_TEXT' : 'PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_UNAVAILABLE'), ENT_QUOTES, 'UTF-8'),
            'link'    => '#',
        ];

        if ($collector) {
            $scan['onclick'] = 'plgHealthcheckFrontenderrorsScan';
        }

        $this->append($event, [$icon, $scan]);
    }

    /**
     * Adds the table of pages ranked by errors.
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

        if ($data['pages'] === []) {
            return;
        }

        $this->loadAssets();

        $settings = $data['settings'];

        $labels = [
            'page'      => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_PAGE'),
            'errors'    => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_ERRORS'),
            'seen'      => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_SEEN'),
            'top'       => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_TOP'),
            'last_seen' => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_LAST_SEEN'),
            'details'   => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_COLUMN_DETAILS'),
            'new'       => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_BADGE_NEW'),
            'regressed' => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_BADGE_REGRESSED'),
        ];

        $this->append($event, [[
            'id'      => 'plg_healthcheck_frontenderrors_table',
            'section' => Text::_('PLG_HEALTHCHECK_FRONTENDERRORS_SECTION'),
            'caption' => Text::sprintf('PLG_HEALTHCHECK_FRONTENDERRORS_TABLE_CAPTION', $settings->periodDays),
            'status'  => $this->tileStatus(),
            'columns' => self::columns($labels, $this->origin()),
            'data'    => self::tableRows(
                $data['pages'],
                $settings->thresholdPage,
                static fn (int $count): string => Text::plural('PLG_HEALTHCHECK_FRONTENDERRORS_N_TIMES', $count)
            ),
        ]]);
    }

    /**
     * Ajax entry point for the admin script: pages, scanresults, details and state.
     *
     * @param   AjaxEvent  $event  The Ajax event
     *
     * @return  void
     *
     * @throws  \RuntimeException  On a missing token or permission, a wrong method or an unknown task
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onAjaxFrontenderrors(AjaxEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator') || !Session::checkToken('get') || !$app->getIdentity()->authorise('core.admin')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->loadLanguage();

        $input    = $app->getInput();
        $task     = $input->getCmd('task');
        $settings = Settings::fromParams($this->params);
        $post     = \in_array($task, ['scanresults', 'state'], true);

        if ($post && strtoupper($input->getMethod()) !== 'POST') {
            throw new \RuntimeException('Method not allowed', 405);
        }

        $result = match ($task) {
            'pages'       => ['pages' => $this->scanList($settings->scanLimit)],
            'scanresults' => ['stored' => $this->storeScan($settings)],
            'details'     => ['html' => $this->details(mb_substr((string) $input->get('page', '', 'raw'), 0, 255), $settings->periodDays)],
            'state'       => ['ok' => $this->repository->setState($input->post->getInt('id'), $input->post->getInt('state', -1), gmdate('Y-m-d H:i:s'))],
            default       => throw new \RuntimeException('Unknown task', 400),
        };

        $event->addResult($result);
    }

    /**
     * The pages Scan now visits: home first, then guest-visible menu items in menu order.
     *
     * @param   string    $home    Path of the site home, with the site base and a trailing slash
     * @param   string[]  $paths   Routed menu item paths, normally including the site base
     * @param   string    $origin  Scheme, host and port of the site, stripped from absolute links
     * @param   integer   $limit   Maximum number of pages
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function scanPageList(string $home, array $paths, string $origin, int $limit): array
    {
        $origin = rtrim($origin, '/');
        $pages  = [$home];

        foreach ($paths as $path) {
            $path = (string) $path;

            if ($origin !== '' && strncasecmp($path, $origin . '/', \strlen($origin) + 1) === 0) {
                $path = substr($path, \strlen($origin));
            }

            if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
                continue;
            }

            $pages[] = $path;
        }

        return \array_slice(array_values(array_unique($pages)), 0, max(0, $limit));
    }

    /**
     * Decode one page of scan results.
     *
     * @param   string  $body  Raw request body
     *
     * @return  array|null  Null when empty, too large or not a JSON object
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function scanPayload(string $body): ?array
    {
        if ($body === '' || \strlen($body) > self::MAX_BODY_BYTES) {
            return null;
        }

        $payload = json_decode($body, true);

        return \is_array($payload) ? $payload : null;
    }

    /**
     * Render the details layout for the errors of one page.
     *
     * @param   string   $layout      Path of the layout file
     * @param   array    $errors      Rows from ErrorRepository::pageErrors()
     * @param   integer  $periodDays  Look-back period in days
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function renderDetails(string $layout, array $errors, int $periodDays): string
    {
        $displayData = ['errors' => $errors, 'periodDays' => $periodDays];

        ob_start();

        try {
            include $layout;
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * Turn the ranked pages into table rows.
     *
     * @param   array    $topPages   Rows from ErrorRepository::topPages()
     * @param   integer  $threshold  Per-page error threshold
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function tableRows(array $topPages, int $threshold, ?callable $timesLabel = null): array
    {
        $timesLabel ??= static fn (int $count): string => (string) $count;
        $rows = [];

        foreach ($topPages as $page) {
            $message = (string) $page['top_message'];

            $rows[] = [
                'page'      => (string) $page['page'],
                'errors'    => (int) $page['errors'],
                'badge'     => Status::badge((int) $page['errors'], $threshold),
                'hits'      => (int) $page['hits'],
                'hits_text' => (string) $timesLabel((int) $page['hits']),
                'top'       => mb_strlen($message) > self::MESSAGE_LENGTH ? mb_substr($message, 0, self::MESSAGE_LENGTH - 1) . '…' : $message,
                'marker'    => !empty($page['has_regressed']) ? 'regressed' : (!empty($page['has_new']) ? 'new' : ''),
                'last_seen' => (string) $page['last_seen'],
            ];
        }

        return $rows;
    }

    /**
     * Table columns. Browser-sourced values are escaped in every custom renderer.
     *
     * @param   array   $labels  Translated labels: page, errors, seen, top, last_seen, details, new, regressed
     * @param   string  $origin  Scheme, host and port of the site, no trailing slash
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function columns(array $labels, string $origin): array
    {
        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $origin = rtrim($origin, '/');

        return [
            [
                'key'      => 'page',
                'title'    => $labels['page'],
                'type'     => 'custom',
                'renderer' => static function ($value) use ($escape, $origin): string {
                    $path = (string) $value;
                    $path = $path !== '' && $path[0] === '/' ? $path : '/' . $path;

                    return '<a href="' . $escape($origin . $path) . '" target="_blank" rel="noopener">' . $escape($value) . '</a>';
                },
            ],
            [
                'key'        => 'errors',
                'title'      => $labels['errors'],
                'type'       => 'badge',
                'badgeClass' => static fn ($value, $item): string => $item['badge'],
            ],
            [
                'key'      => 'hits',
                'title'    => $labels['seen'],
                'type'     => 'custom',
                'renderer' => static fn ($value, $item): string => $escape($item['hits_text'] ?? $value),
            ],
            [
                'key'      => 'top',
                'title'    => $labels['top'],
                'type'     => 'custom',
                'renderer' => static function ($value, $item) use ($escape, $labels): string {
                    $html = $escape($value);

                    if (($item['marker'] ?? '') === 'regressed') {
                        $html .= ' <span class="badge bg-danger">' . $escape($labels['regressed']) . '</span>';
                    } elseif (($item['marker'] ?? '') === 'new') {
                        $html .= ' <span class="badge bg-info">' . $escape($labels['new']) . '</span>';
                    }

                    return $html;
                },
            ],
            ['key' => 'last_seen', 'title' => $labels['last_seen'], 'type' => 'date'],
            [
                'key'      => 'details',
                'title'    => $labels['details'],
                'type'     => 'custom',
                'renderer' => static fn ($value, $item): string => '<button type="button" class="btn btn-sm btn-outline-secondary plg-hc-fe-details" data-hc-fe-page="'
                    . $escape($item['page'] ?? '') . '">' . $escape($labels['details']) . '</button>',
            ],
        ];
    }

    /**
     * Routed paths of the pages Scan now visits.
     *
     * @param   integer  $limit  Maximum number of pages
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function scanList(int $limit): array
    {
        $paths = [];

        foreach ($this->scanPages->itemIds($limit, Access::getAuthorisedViewLevels(0), gmdate('Y-m-d H:i:s')) as $id) {
            try {
                $paths[] = Route::link('site', 'index.php?Itemid=' . $id, false);
            } catch (\Throwable $e) {
                // A menu item that cannot be routed is not scanned.
            }
        }

        return self::scanPageList(Uri::root(true) . '/', $paths, $this->origin(), $limit);
    }

    /**
     * Store the reports of one scanned page, read from the request body.
     *
     * @param   Settings  $settings  The plugin settings
     *
     * @return  integer  Number of reports stored
     *
     * @since   __DEPLOY_VERSION__
     */
    private function storeScan(Settings $settings): int
    {
        $payload = self::scanPayload((string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1));

        if ($payload === null) {
            return 0;
        }

        $reports = array_values(array_filter(
            ReportNormaliser::normalise($payload, Uri::root()),
            static fn (array $report): bool => NoiseFilter::keep($report, $settings->ignorePatterns)
        ));

        // Not in a transaction: record() relies on autocommit to recover from a concurrent insert.
        return $reports ? $this->repository->record($reports, 'scan', gmdate('Y-m-d H:i:s')) : 0;
    }

    /**
     * Details dialog HTML for one page.
     *
     * @param   string   $page        Page path
     * @param   integer  $periodDays  Look-back period in days
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function details(string $page, int $periodDays): string
    {
        [$from, $to] = self::period($periodDays, time(), 0);

        return self::renderDetails(
            PluginHelper::getLayoutPath('healthcheck', 'frontenderrors', 'details'),
            $page === '' ? [] : $this->repository->pageErrors($page, $from, $to),
            $periodDays
        );
    }

    /**
     * Add the admin script, its options and strings to the page, once.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function loadAssets(): void
    {
        if ($this->assetsLoaded) {
            return;
        }

        $this->assetsLoaded = true;
        $document           = $this->getApplication()->getDocument();

        if (!$document || !method_exists($document, 'getWebAssetManager')) {
            return;
        }

        $wa = $document->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('plg_healthcheck_frontenderrors');
        $wa->useScript('plg_healthcheck_frontenderrors.admin');

        $document->addScriptOptions('plg_healthcheck_frontenderrors', [
            'ajax'   => 'index.php?option=com_ajax&group=healthcheck&plugin=frontenderrors&format=json',
            'token'  => Session::getFormToken(),
            'origin' => $this->origin(),
        ]);

        foreach (self::SCRIPT_STRINGS as $key) {
            Text::script($key);
        }
    }

    /**
     * Scheme, host and port of the site.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function origin(): string
    {
        return Uri::getInstance(Uri::root())->toString(['scheme', 'host', 'port']);
    }

    /**
     * First and last day (UTC, `Y-m-d`) of a period ending a number of periods before today.
     *
     * @param   integer  $days     Length of the period in days
     * @param   integer  $now      Current Unix time
     * @param   integer  $periods  0 for the current period, 1 for the one before
     *
     * @return  string[]  [first day, last day]
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function period(int $days, int $now, int $periods): array
    {
        $end = $now - $periods * $days * 86400;

        return [gmdate('Y-m-d', $end - ($days - 1) * 86400), gmdate('Y-m-d', $end)];
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
     * Settings, summaries and ranked pages for this request. Old data is purged once.
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

        $settings = Settings::fromParams($this->params);
        $now      = time();

        [$from, $today]      = self::period($settings->periodDays, $now, 0);
        [$prevFrom, $prevTo] = self::period($settings->periodDays, $now, 1);

        $data = [
            'settings' => $settings,
            'state'    => $settings->collectionState($today),
            'summary'  => ['errors' => 0, 'pages' => 0, 'regressed' => false],
            'previous' => 0,
            'pages'    => [],
        ];

        try {
            if (!$this->purged) {
                $this->purged = true;
                $this->repository->purge(gmdate('Y-m-d', $now - $settings->retentionDays * 86400), gmdate('Y-m-d H:i:s', $now));
            }

            $data['summary']  = $this->repository->summary($from, $today);
            $data['previous'] = $this->repository->summary($prevFrom, $prevTo)['errors'];
            $data['pages']    = $this->repository->topPages($from, $today, self::TABLE_LIMIT);
        } catch (\Throwable $e) {
            Log::add('Frontend Errors Health Check: could not read the collected errors: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
        }

        return $this->data = $data;
    }

    /**
     * Whether the storage limits were reached.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function saturated(): bool
    {
        try {
            return $this->repository->isSaturated();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Tile status for the current data.
     *
     * @return  string  success|warning|error
     *
     * @since   __DEPLOY_VERSION__
     */
    private function tileStatus(): string
    {
        $data  = $this->data();
        $worst = $data['pages'] === [] ? 0 : (int) $data['pages'][0]['errors'];

        return Status::tile($data['summary']['errors'], $worst, $data['settings']->thresholdPage, $data['summary']['regressed']);
    }

    /**
     * Edit URL of one of the two Frontend Errors plugins.
     *
     * @param   string  $folder  Plugin group: healthcheck or system
     *
     * @return  string  Empty string when the extension cannot be found
     *
     * @since   __DEPLOY_VERSION__
     */
    private function editLink(string $folder): string
    {
        try {
            $db      = $this->getDatabase();
            $type    = 'plugin';
            $element = 'frontenderrors';
            $query   = $db->createQuery()
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('folder') . ' = :folder')
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':type', $type)
                ->bind(':folder', $folder)
                ->bind(':element', $element);

            $id = (int) $db->setQuery($query)->loadResult();
        } catch (\Throwable $e) {
            return '#';
        }

        return $id > 0 ? 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . $id : '#';
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
