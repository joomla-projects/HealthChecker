<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Securityheaders
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Securityheaders\Extension;

use Joomla\CMS\Cache\CacheControllerFactoryAwareTrait;
use Joomla\CMS\Cache\Controller\OutputController;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;
use Joomla\Plugin\Healthcheck\Securityheaders\Headers\FixLink;
use Joomla\Plugin\Healthcheck\Securityheaders\Headers\HeaderFetcher;
use Joomla\Plugin\Healthcheck\Securityheaders\Headers\HeaderScore;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Health Check plugin scoring the HTTP security headers of the site's homepage
 *
 * @since  __DEPLOY_VERSION__
 */
final class Securityheaders extends CMSPlugin implements SubscriberInterface
{
    use CacheControllerFactoryAwareTrait;
    use DatabaseAwareTrait;

    /**
     * Cache group of the fetched responses
     *
     * @since  __DEPLOY_VERSION__
     */
    private const CACHE_GROUP = 'plg_healthcheck_securityheaders';

    /**
     * Display names of the scored items
     *
     * @since  __DEPLOY_VERSION__
     */
    private const NAMES = [
        'hsts'        => 'Strict-Transport-Security',
        'csp'         => 'Content-Security-Policy',
        'frame'       => 'X-Frame-Options / frame-ancestors',
        'nosniff'     => 'X-Content-Type-Options',
        'referrer'    => 'Referrer-Policy',
        'permissions' => 'Permissions-Policy',
        'coop'        => 'Cross-Origin-Opener-Policy',
        'leak'        => 'Server / X-Powered-By',
    ];

    /**
     * The response used for this request, looked up once
     *
     * @var    array|null
     * @since  __DEPLOY_VERSION__
     */
    private ?array $response = null;

    /**
     * Extension id of the System - HTTP Headers plugin, looked up once
     *
     * @var    integer|null
     * @since  __DEPLOY_VERSION__
     */
    private ?int $httpHeadersId = null;

    /**
     * Whether the extension id was looked up
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    private bool $httpHeadersIdLoaded = false;

    /**
     * Constructor
     *
     * @param   array          $config   The plugin configuration
     * @param   HeaderFetcher  $fetcher  Requests the homepage
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(array $config, private readonly HeaderFetcher $fetcher)
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
            'onHealthcheckGetGauges' => 'onHealthcheckGetGauges',
            'onHealthcheckGetTables' => 'onHealthcheckGetTables',
            'onHealthcheckGetIcons'  => 'onHealthcheckGetIcons',
            'onAjaxSecurityheaders'  => 'onAjaxSecurityheaders',
        ];
    }

    /**
     * Adds the score gauge when the headers could be read.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onHealthcheckGetGauges(HealthChecksEvent $event): void
    {
        if (!$this->isAllowed($event) || !$this->response()['ok']) {
            return;
        }

        $score = $this->score();

        $this->append($event, [[
            'id'                      => 'plg_healthcheck_securityheaders_gauge',
            'section'                 => Text::_('PLG_HEALTHCHECK_SECURITYHEADERS_SECTION'),
            'label'                   => Text::_('PLG_HEALTHCHECK_SECURITYHEADERS_GAUGE_LABEL'),
            'sublabel'                => Text::sprintf('PLG_HEALTHCHECK_SECURITYHEADERS_GAUGE_SUBLABEL', $score['score']),
            'note'                    => Text::sprintf('PLG_HEALTHCHECK_SECURITYHEADERS_CHECKED', $this->date($this->response()['checkedAt'])),
            'score'                   => $score['score'],
            'unit'                    => '',
            'score_min'               => 0,
            'score_max'               => 100,
            'score_threshold_warning' => 50,
            'score_threshold_success' => 80,
            'status'                  => $score['status'],
            'link'                    => $this->refreshLink(),
            'linktitle'               => Text::_('PLG_HEALTHCHECK_SECURITYHEADERS_CHECK_NOW'),
        ]]);
    }

    /**
     * Adds the per-header table when the headers could be read.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onHealthcheckGetTables(HealthChecksEvent $event): void
    {
        if (!$this->isAllowed($event) || !$this->response()['ok']) {
            return;
        }

        $response = $this->response();
        $score    = $this->score();
        $rows     = [];

        foreach ($score['items'] as $item) {
            $link = FixLink::for($item, $this->httpHeadersId());

            $rows[] = [
                'header'    => self::NAMES[$item['key']],
                'value'     => self::shorten($item['value']),
                'points'    => $item['max'] === 0 ? (string) $item['points'] : $item['points'] . ' / ' . $item['max'],
                'fix'       => $item['fix'] === null ? '' : Text::_($item['fix']),
                'status'    => $item['status'],
                'link'      => $link === null ? '' : $link['url'],
                'linkLabel' => $link === null ? '' : Text::_($link['label']),
            ];
        }

        $statuses = array_column($rows, 'status');

        $this->append($event, [[
            'id'      => 'plg_healthcheck_securityheaders_table',
            'section' => Text::_('PLG_HEALTHCHECK_SECURITYHEADERS_SECTION'),
            'caption' => Text::sprintf('PLG_HEALTHCHECK_SECURITYHEADERS_TABLE_CAPTION', $response['url'], $response['status']),
            'status'  => \in_array('error', $statuses, true) ? 'error' : (\in_array('warning', $statuses, true) ? 'warning' : 'success'),
            'columns' => [
                ['key' => 'header', 'title' => Text::_('PLG_HEALTHCHECK_SECURITYHEADERS_COLUMN_HEADER')],
                ['key' => 'value', 'title' => Text::_('PLG_HEALTHCHECK_SECURITYHEADERS_COLUMN_VALUE')],
                [
                    'key'        => 'points',
                    'title'      => Text::_('PLG_HEALTHCHECK_SECURITYHEADERS_COLUMN_POINTS'),
                    'type'       => 'badge',
                    'badgeClass' => static fn ($value, $item): string => match ($item['status']) {
                        'success' => 'success',
                        'warning' => 'warning',
                        default   => 'danger',
                    },
                ],
                [
                    'key'      => 'fix',
                    'title'    => Text::_('PLG_HEALTHCHECK_SECURITYHEADERS_COLUMN_FIX'),
                    'type'     => 'custom',
                    'renderer' => static function ($value, $item): string {
                        $html = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

                        if (($item['link'] ?? '') !== '') {
                            $html .= '<div class="mt-1"><a class="btn btn-sm btn-outline-primary" href="' . htmlspecialchars($item['link'], ENT_QUOTES, 'UTF-8') . '">'
                                . htmlspecialchars($item['linkLabel'], ENT_QUOTES, 'UTF-8') . '</a></div>';
                        }

                        return $html;
                    },
                ],
            ],
            'data' => $rows,
        ]]);
    }

    /**
     * Adds a neutral icon when the headers could not be read.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onHealthcheckGetIcons(HealthChecksEvent $event): void
    {
        if (!$this->isAllowed($event) || $this->response()['ok']) {
            return;
        }

        $response = $this->response();
        $reason   = str_starts_with($response['reason'], 'PLG_HEALTHCHECK_SECURITYHEADERS_REASON_') ? Text::_($response['reason']) : $response['reason'];

        $reason = rtrim($reason, '. ') . '.';

        $this->append($event, [[
            'link'    => $this->refreshLink(),
            'icon'    => 'icon-shield-alt',
            'section' => Text::_('PLG_HEALTHCHECK_SECURITYHEADERS_SECTION'),
            'name'    => 'PLG_HEALTHCHECK_SECURITYHEADERS_GAUGE_LABEL',
            'text'    => Text::_('PLG_HEALTHCHECK_SECURITYHEADERS_UNCHECKED'),
            'title'   => Text::sprintf('PLG_HEALTHCHECK_SECURITYHEADERS_TITLE_REASON', $reason) . ' '
                . Text::sprintf('PLG_HEALTHCHECK_SECURITYHEADERS_CHECKED', $this->date($response['checkedAt'])),
            'id' => 'plg_healthcheck_securityheaders',
        ]]);
    }

    /**
     * Forget the stored response so the next dashboard load fetches the headers again.
     *
     * @param   AjaxEvent  $event  The Ajax event
     *
     * @return  void
     *
     * @throws  \RuntimeException  On a missing token or permission
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onAjaxSecurityheaders(AjaxEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator') || !Session::checkToken('get') || !$app->getIdentity()->authorise('core.admin')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        try {
            $this->cache()->remove(Uri::root());
        } catch (\Throwable $e) {
            Log::add('Security Headers Health Check: could not clear the stored result: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
        }

        $return = base64_decode($app->getInput()->getBase64('return', ''));

        $app->redirect(Uri::isInternal($return) ? $return : 'index.php?option=com_cpanel&view=cpanel&dashboard=healthcheck');
    }

    /**
     * Right group and a Super User.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private function isAllowed(HealthChecksEvent $event): bool
    {
        if ($event->getContext() !== $this->params->get('context', 'security') || !$this->getApplication()->getIdentity()->authorise('core.admin')) {
            return false;
        }

        $this->loadLanguage();

        return true;
    }

    /**
     * The response for this request: a fresh cached one, or a new fetch that is then stored.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function response(): array
    {
        if ($this->response !== null) {
            return $this->response;
        }

        $url = Uri::root();
        $now = time();

        try {
            $cached = $this->cache()->get($url);
        } catch (\Throwable $e) {
            $cached = false;
        }

        if (HeaderFetcher::isUsable($cached, $now)) {
            return $this->response = $cached;
        }

        try {
            $response = $this->fetcher->fetch($url, HeaderFetcher::timeout($this->params->get('timeout')));
        } catch (\Throwable $e) {
            Log::add('Security Headers Health Check: fetch failed: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
            $response = HeaderFetcher::failure($url, HeaderFetcher::REASON_NO_HEADERS, $now);
        }

        try {
            $this->cache()->store($response, $url);
        } catch (\Throwable $e) {
            Log::add('Security Headers Health Check: could not store the result: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
        }

        return $this->response = $response;
    }

    /**
     * Score of the current response.
     *
     * @return  array  HeaderScore::evaluate() result
     *
     * @since   __DEPLOY_VERSION__
     */
    private function score(): array
    {
        $response = $this->response();

        return HeaderScore::evaluate($response['headers'], str_starts_with(strtolower($response['url']), 'https://'));
    }

    /**
     * Extension id of the System - HTTP Headers plugin, enabled or not.
     *
     * @return  integer|null  Null when it cannot be found
     *
     * @since   __DEPLOY_VERSION__
     */
    private function httpHeadersId(): ?int
    {
        if ($this->httpHeadersIdLoaded) {
            return $this->httpHeadersId;
        }

        $this->httpHeadersIdLoaded = true;

        try {
            $db      = $this->getDatabase();
            $type    = 'plugin';
            $folder  = 'system';
            $element = 'httpheaders';
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

            $this->httpHeadersId = $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            Log::add('Security Headers Health Check: could not find the HTTP Headers plugin: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
        }

        return $this->httpHeadersId;
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

    /**
     * The token-protected "check now" link back to this dashboard.
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function refreshLink(): string
    {
        return 'index.php?option=com_ajax&group=healthcheck&plugin=securityheaders&format=raw&' . Session::getFormToken() . '=1&return='
            . urlencode(base64_encode(Uri::getInstance()->toString()));
    }

    /**
     * Format a Unix time for display in the user's time zone.
     *
     * @param   integer  $time  Unix time
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private function date(int $time): string
    {
        return HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $time), Text::_('DATE_FORMAT_LC2'));
    }

    /**
     * Shorten a header value for the table.
     *
     * @param   string  $value  Header value
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function shorten(string $value): string
    {
        return mb_strlen($value) > 80 ? mb_substr($value, 0, 79) . '…' : $value;
    }

    /**
     * Get the cache controller holding fetched responses.
     *
     * @return  OutputController
     *
     * @since   __DEPLOY_VERSION__
     */
    private function cache(): OutputController
    {
        return $this->getCacheControllerFactory()->createCacheController(
            'output',
            ['defaultgroup' => self::CACHE_GROUP, 'caching' => true, 'lifetime' => 1440, 'language' => 'en-GB']
        );
    }
}
