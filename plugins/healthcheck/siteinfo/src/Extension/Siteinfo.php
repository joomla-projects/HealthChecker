<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Siteinfo
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Siteinfo\Extension;

use Joomla\CMS\Cache\CacheControllerFactoryAwareTrait;
use Joomla\CMS\Cache\Controller\OutputController;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Http\HttpFactory;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;
use Joomla\Plugin\Healthcheck\Siteinfo\Support\SupportData;
use Joomla\Plugin\Healthcheck\Siteinfo\Support\SystemReport;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Health Check plugin reporting software versions, their support status and PHP settings
 *
 * @since  __DEPLOY_VERSION__
 */
final class Siteinfo extends CMSPlugin implements SubscriberInterface
{
    use CacheControllerFactoryAwareTrait;
    use DatabaseAwareTrait;

    /**
     * Cache group of the requirements refreshed from the manual
     *
     * @since  __DEPLOY_VERSION__
     */
    private const CACHE_GROUP = 'plg_healthcheck_siteinfo';

    /**
     * Cache id of the requirements refreshed from the manual
     *
     * @since  __DEPLOY_VERSION__
     */
    private const STORE_ID = 'requirements';

    /**
     * The stored copy of the requirements, read once per request
     *
     * @var    array|null
     * @since  __DEPLOY_VERSION__
     */
    private ?array $storedCopy = null;

    /**
     * Whether the stored copy has been read in this request
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    private bool $storedRead = false;

    /**
     * The software version rows, built once per request
     *
     * @var    array|null
     * @since  __DEPLOY_VERSION__
     */
    private ?array $versionRows = null;

    /**
     * Constructor
     *
     * @param   array        $config       The plugin configuration
     * @param   HttpFactory  $httpFactory  The HTTP factory used to refresh requirements from the manual
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(array $config, private readonly HttpFactory $httpFactory)
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
            'onAjaxSiteinfo'         => 'onAjaxSiteinfo',
        ];
    }

    /**
     * Adds the Software Support icon and the Refresh Requirements button.
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

        $rows      = $this->collectVersionRows();
        $attention = array_filter($rows, static fn (array $row): bool => \in_array($row['status'], [SupportData::STATUS_CRITICAL, SupportData::STATUS_WARNING], true));
        $return    = base64_encode(Uri::getInstance()->toString());

        $result   = $event->getArgument('result', []);
        $result[] = [
            [
                'link'    => '#plg_healthcheck_siteinfo_versions',
                'icon'    => 'fas fa-server',
                'section' => Text::_('PLG_HEALTHCHECK_SITEINFO_SECTION'),
                'amount'  => \count($attention),
                'text'    => Text::_('PLG_HEALTHCHECK_SITEINFO_ICON_TEXT'),
                'id'      => 'plg_healthcheck_siteinfo_support',
                'status'  => $this->tableStatus(array_column($rows, 'status')),
            ],
            [
                'link'    => 'index.php?option=com_ajax&group=healthcheck&plugin=siteinfo&format=raw&' . Session::getFormToken() . '=1&return=' . urlencode($return),
                'icon'    => 'icon-sync',
                'section' => Text::_('PLG_HEALTHCHECK_SITEINFO_SECTION'),
                'text'    => Text::_('PLG_HEALTHCHECK_SITEINFO_ICON_REFRESH'),
                'id'      => 'plg_healthcheck_siteinfo_refresh',
            ],
        ];

        $event->setArgument('result', $result);
    }

    /**
     * Adds the Software Versions and PHP Settings tables.
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

        $stored = $this->readStored();
        $rows   = $this->collectVersionRows();

        $versions = [
            'id'      => 'plg_healthcheck_siteinfo_versions',
            'section' => Text::_('PLG_HEALTHCHECK_SITEINFO_SECTION'),
            'caption' => $stored ? Text::sprintf('PLG_HEALTHCHECK_SITEINFO_TABLE_VERSIONS_MANUAL', $stored['retrieved']) : Text::_('PLG_HEALTHCHECK_SITEINFO_TABLE_VERSIONS_BUILTIN'),
            'status'  => $this->tableStatus(array_column($rows, 'status')),
            'columns' => [
                ['key' => 'label', 'title' => Text::_('PLG_HEALTHCHECK_SITEINFO_COLUMN_COMPONENT')],
                ['key' => 'version', 'title' => Text::_('PLG_HEALTHCHECK_SITEINFO_COLUMN_VERSION')],
                [
                    'key'        => 'text',
                    'title'      => Text::_('PLG_HEALTHCHECK_SITEINFO_COLUMN_STATUS'),
                    'type'       => 'badge',
                    'badgeClass' => static fn ($value, $item): string => self::badgeClass($item['status']),
                ],
                ['key' => 'ends', 'title' => Text::_('PLG_HEALTHCHECK_SITEINFO_COLUMN_ENDS')],
            ],
            'data' => $rows,
        ];

        $settings = $this->collectSettingsRows($stored['memory'] ?? null);

        $settingsTable = [
            'id'      => 'plg_healthcheck_siteinfo_settings',
            'section' => Text::_('PLG_HEALTHCHECK_SITEINFO_SECTION'),
            'caption' => Text::_('PLG_HEALTHCHECK_SITEINFO_TABLE_SETTINGS'),
            'status'  => $this->tableStatus(array_column($settings, 'status')),
            'columns' => [
                ['key' => 'setting', 'title' => Text::_('PLG_HEALTHCHECK_SITEINFO_COLUMN_SETTING')],
                [
                    'key'        => 'current',
                    'title'      => Text::_('PLG_HEALTHCHECK_SITEINFO_COLUMN_CURRENT'),
                    'type'       => 'badge',
                    'badgeClass' => static fn ($value, $item): string => self::badgeClass($item['status']),
                ],
                ['key' => 'recommended', 'title' => Text::_('PLG_HEALTHCHECK_SITEINFO_COLUMN_RECOMMENDED')],
            ],
            'data' => $settings,
        ];

        $result   = $event->getArgument('result', []);
        $result[] = [$versions, $settingsTable];

        $event->setArgument('result', $result);
    }

    /**
     * Refreshes Joomla's requirements from the manual when a Super User clicks Refresh Requirements.
     *
     * @param   AjaxEvent  $event  The Ajax event
     *
     * @return  void
     *
     * @throws  \RuntimeException  On a missing token or permission
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onAjaxSiteinfo(AjaxEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator') || !Session::checkToken('get') || !$app->getIdentity()->authorise('core.admin')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->loadLanguage();

        $stored = $this->readStored();

        if ($stored !== null && time() - $stored['fetched'] < 3600) {
            $app->enqueueMessage(Text::_('PLG_HEALTHCHECK_SITEINFO_REFRESH_RECENT'), 'info');
        } else {
            try {
                $response = $this->httpFactory->getHttp()->get(SupportData::MANUAL_URL, [], 5);

                if ($response->getStatusCode() !== 200) {
                    throw new \RuntimeException('HTTP ' . $response->getStatusCode());
                }

                $parsed = SupportData::parseManual((string) $response->getBody(), (int) JVERSION);

                $this->store()->store(['fetched' => time(), 'retrieved' => gmdate('Y-m-d')] + $parsed, self::STORE_ID);
                $this->storedRead = false;
                $app->enqueueMessage(Text::_('PLG_HEALTHCHECK_SITEINFO_REFRESH_DONE'), 'success');
            } catch (\Throwable $e) {
                Log::add('Site Information: could not refresh requirements from the manual: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
                $app->enqueueMessage(Text::sprintf('PLG_HEALTHCHECK_SITEINFO_REFRESH_FAILED', $e->getMessage()), 'warning');
            }
        }

        $return = base64_decode($app->getInput()->getBase64('return', ''));

        $app->redirect(Uri::isInternal($return) ? $return : 'index.php?option=com_cpanel&view=cpanel&dashboard=healthcheck');
    }

    /**
     * Whether this plugin should answer the event: right group and a user allowed to see server details.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function isAllowed(HealthChecksEvent $event): bool
    {
        if ($event->getContext() !== $this->params->get('context', 'general')) {
            return false;
        }

        if (!$this->getApplication()->getIdentity()->authorise('core.admin')) {
            return false;
        }

        $this->loadLanguage();

        return true;
    }

    /**
     * Detect versions and build translated table rows, once per request.
     *
     * @return  array  List of ['label', 'version', 'status', 'text', 'ends']
     *
     * @since   __DEPLOY_VERSION__
     */
    private function collectVersionRows(): array
    {
        if ($this->versionRows !== null) {
            return $this->versionRows;
        }

        $stored = $this->readStored();
        $report = new SystemReport(new SupportData($stored['requirements'] ?? []), new \DateTimeImmutable('today', new \DateTimeZone('UTC')));
        $rows   = [];

        $rows[] = $this->translateRow(Text::_('PLG_HEALTHCHECK_SITEINFO_PRODUCT_PHP'), $report->versionRow('php', PHP_VERSION));

        try {
            $db      = $this->getDatabase();
            $product = $db->getServerType() === 'postgresql' ? 'postgresql' : (method_exists($db, 'isMariaDb') && $db->isMariaDb() ? 'mariadb' : 'mysql');
            $label   = Text::_('PLG_HEALTHCHECK_SITEINFO_PRODUCT_' . strtoupper($product));
            $rows[]  = $this->translateRow($label, $report->versionRow($product, (string) $db->getVersion()));
        } catch (\Throwable $e) {
            $rows[] = $this->translateRow(Text::_('PLG_HEALTHCHECK_SITEINFO_COMPONENT_DATABASE'), $report->versionRow('mysql', ''));
        }

        $rows[] = $this->translateRow(Text::_('PLG_HEALTHCHECK_SITEINFO_PRODUCT_JOOMLA'), $report->versionRow('joomla', JVERSION));

        $server = SystemReport::parseServerSoftware($this->getApplication()->getInput()->server->getString('SERVER_SOFTWARE', ''));
        $label  = Text::_('PLG_HEALTHCHECK_SITEINFO_COMPONENT_WEBSERVER') . ($server['name'] !== '' ? ' (' . $server['name'] . ')' : '');

        if ($server['product'] !== null) {
            $rows[] = $this->translateRow($label, $report->versionRow($server['product'], $server['version']));
        } else {
            $rows[] = ['label' => $label, 'version' => $server['version'], 'status' => SupportData::STATUS_NONE, 'text' => '', 'ends' => ''];
        }

        $rows[] = [
            'label'   => Text::_('PLG_HEALTHCHECK_SITEINFO_COMPONENT_OS'),
            'version' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'status'  => SupportData::STATUS_NONE,
            'text'    => '',
            'ends'    => '',
        ];

        return $this->versionRows = $rows;
    }

    /**
     * Translate the messages of a report row.
     *
     * @param   string  $label  The component label
     * @param   array   $row    A row from SystemReport::versionRow()
     *
     * @return  array  ['label', 'version', 'status', 'text', 'ends']
     *
     * @since   __DEPLOY_VERSION__
     */
    private function translateRow(string $label, array $row): array
    {
        $messages = array_map(static fn (array $message): string => Text::sprintf($message[0], ...$message[1]), $row['messages']);

        return [
            'label'   => $label,
            'version' => $row['version'],
            'status'  => $row['status'],
            'text'    => implode('. ', $messages),
            'ends'    => $row['ends'],
        ];
    }

    /**
     * Read PHP settings and build translated table rows.
     *
     * @param   string|null  $memoryLimit  Memory limit refreshed from the manual, if any
     *
     * @return  array  List of ['setting', 'current', 'recommended', 'status']
     *
     * @since   __DEPLOY_VERSION__
     */
    private function collectSettingsRows(?string $memoryLimit): array
    {
        $ini = [];

        foreach (['memory_limit', 'max_execution_time', 'post_max_size', 'upload_max_filesize', 'display_errors'] as $setting) {
            $ini[$setting] = (string) \ini_get($setting);
        }

        $opcache = \extension_loaded('Zend OPcache') && filter_var(\ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
        $rows    = SystemReport::settingsRows($ini, $opcache, $memoryLimit ?? SystemReport::MEMORY_LIMIT);

        return array_map(
            static function (array $row): array {
                if (\in_array($row['current'], ['JENABLED', 'JDISABLED', 'JON', 'JOFF'], true)) {
                    $row['current'] = Text::_($row['current']);
                }

                $row['recommended'] = Text::sprintf($row['recommended'][0], ...$row['recommended'][1]);

                return $row;
            },
            $rows
        );
    }

    /**
     * Map a plugin status to the table layout status.
     *
     * @param   array  $statuses  Row statuses
     *
     * @return  string  success, warning or error
     *
     * @since   __DEPLOY_VERSION__
     */
    private function tableStatus(array $statuses): string
    {
        return match (SystemReport::worst($statuses)) {
            SupportData::STATUS_CRITICAL => 'error',
            SupportData::STATUS_WARNING  => 'warning',
            default                      => 'success',
        };
    }

    /**
     * Map a plugin status to a Bootstrap badge colour.
     *
     * @param   string  $status  The status
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function badgeClass(string $status): string
    {
        return match ($status) {
            SupportData::STATUS_CRITICAL => 'danger',
            SupportData::STATUS_WARNING  => 'warning',
            SupportData::STATUS_HEALTHY  => 'success',
            default                      => 'secondary',
        };
    }

    /**
     * Get the cache controller holding requirements refreshed from the manual.
     *
     * @return  OutputController
     *
     * @since   __DEPLOY_VERSION__
     */
    private function store(): OutputController
    {
        return $this->getCacheControllerFactory()->createCacheController(
            'output',
            ['defaultgroup' => self::CACHE_GROUP, 'caching' => true, 'lifetime' => 43200, 'language' => 'en-GB']
        );
    }

    /**
     * Read requirements refreshed from the manual, without fetching.
     *
     * @return  array|null  ['fetched' => int, 'retrieved' => 'Y-m-d', 'requirements' => array, 'memory' => ?string] or null
     *
     * @since   __DEPLOY_VERSION__
     */
    private function readStored(): ?array
    {
        if ($this->storedRead) {
            return $this->storedCopy;
        }

        $this->storedRead = true;
        $this->storedCopy = null;

        try {
            $stored = $this->store()->get(self::STORE_ID);
        } catch (\Throwable $e) {
            return null;
        }

        if (
            \is_array($stored)
            && isset($stored['fetched'], $stored['retrieved'], $stored['requirements'])
            && \is_int($stored['fetched'])
            && \is_string($stored['retrieved'])
            && \is_array($stored['requirements'])
            && (!isset($stored['memory']) || \is_string($stored['memory']))
        ) {
            $this->storedCopy = $stored;
        }

        return $this->storedCopy;
    }
}
