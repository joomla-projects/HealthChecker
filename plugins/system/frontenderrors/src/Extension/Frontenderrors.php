<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\System\Frontenderrors\Extension;

use Joomla\CMS\Event\Application\BeforeCompileHeadEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\Healthcheck\Frontenderrors\Model\ErrorRepository;
use Joomla\Plugin\Healthcheck\Frontenderrors\Report\NoiseFilter;
use Joomla\Plugin\Healthcheck\Frontenderrors\Report\ReportNormaliser;
use Joomla\Plugin\Healthcheck\Frontenderrors\Settings\Settings;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Collector for the Health Check - Frontend Errors plugin.
 *
 * Injects a small error-collector script on site pages and receives its beacons.
 * All shared logic and the settings live in the Health Check plugin.
 *
 * @since  __DEPLOY_VERSION__
 */
final class Frontenderrors extends CMSPlugin implements SubscriberInterface
{
    /**
     * Maximum accepted beacon body, in bytes.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const MAX_BODY_BYTES = 8192;

    /**
     * The error repository, null while the Health Check plugin is disabled.
     *
     * @var    ErrorRepository|null
     * @since  __DEPLOY_VERSION__
     */
    private ?ErrorRepository $repository;

    /**
     * Constructor.
     *
     * @param   array                $config      The plugin configuration
     * @param   ErrorRepository|null $repository  The error repository, if the Health Check plugin is enabled
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(array $config, ?ErrorRepository $repository = null)
    {
        parent::__construct($config);

        $this->repository = $repository;
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
            'onBeforeCompileHead'  => 'onBeforeCompileHead',
            'onAjaxFrontenderrors' => 'onAjaxFrontenderrors',
        ];
    }

    /**
     * Decide whether the collector script is added to the page.
     *
     * @param   boolean  $isSite              Whether this is the site client
     * @param   boolean  $healthcheckEnabled  Whether the Health Check plugin is enabled
     * @param   boolean  $collecting          Whether collection is currently on
     * @param   boolean  $scan                Whether the request is a scan visit (hc_scan=1)
     * @param   string   $format              The document format
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function shouldInject(bool $isSite, bool $healthcheckEnabled, bool $collecting, bool $scan, string $format): bool
    {
        if (!$isSite || !$healthcheckEnabled || $format !== 'html') {
            return false;
        }

        return $collecting || $scan;
    }

    /**
     * Decide whether a beacon body is accepted.
     *
     * @param   string   $body        The raw request body
     * @param   boolean  $collecting  Whether collection is currently on
     *
     * @return  array|null  The decoded payload, or null when rejected
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function acceptBody(string $body, bool $collecting): ?array
    {
        if (!$collecting || $body === '' || \strlen($body) > self::MAX_BODY_BYTES) {
            return null;
        }

        $payload = json_decode($body, true);

        return \is_array($payload) ? $payload : null;
    }

    /**
     * Whether the request method may carry a beacon (POST only).
     *
     * @param   string  $method  The request method
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function isBeaconMethod(string $method): bool
    {
        return strtoupper($method) === 'POST';
    }

    /**
     * Add the collector script to site pages.
     *
     * @param   BeforeCompileHeadEvent  $event  The event
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onBeforeCompileHead(BeforeCompileHeadEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $enabled  = PluginHelper::isEnabled('healthcheck', 'frontenderrors');
        $settings = $enabled ? $this->getSettings() : null;
        $document = $event->getDocument();
        $scan     = $app->getInput()->getInt('hc_scan') === 1;

        if (
            !$settings
            || !self::shouldInject(true, $enabled, $settings->isCollecting(gmdate('Y-m-d')), $scan, $document->getType())
        ) {
            return;
        }

        $wa = $document->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('plg_system_frontenderrors');
        $wa->useScript('plg_system_frontenderrors.collector');

        // Options ride on the script tag: the script options element is rendered after a first-loaded script.
        $wa->getAsset('script', 'plg_system_frontenderrors.collector')->setAttribute('data-options', json_encode([
            'endpoint' => Uri::root(true) . '/index.php?option=com_ajax&group=system&plugin=frontenderrors&format=json',
            'sample'   => $settings->sampleRate,
            'console'  => $settings->collectConsole,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Receive a beacon from the collector. Always answers 204 with no body.
     *
     * @param   AjaxEvent  $event  The event
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onAjaxFrontenderrors(AjaxEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        try {
            $settings = $this->repository
                && self::isBeaconMethod((string) $app->getInput()->getMethod())
                && PluginHelper::isEnabled('healthcheck', 'frontenderrors')
                ? $this->getSettings()
                : null;

            if ($settings) {
                $body    = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
                $payload = self::acceptBody($body, $settings->isCollecting(gmdate('Y-m-d')));

                if ($payload !== null) {
                    $reports = array_values(array_filter(
                        ReportNormaliser::normalise($payload, Uri::root()),
                        static fn (array $report): bool => NoiseFilter::keep($report, $settings->ignorePatterns)
                    ));

                    if ($reports) {
                        $this->repository->record($reports, 'visitor', gmdate('Y-m-d H:i:s'));
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::add('Frontend Errors beacon failed: ' . $e->getMessage(), Log::WARNING, 'frontenderrors');
        }

        $app->setHeader('status', 204, true);
        $app->sendHeaders();
        $app->close();
    }

    /**
     * Read the Health Check plugin settings.
     *
     * @return  Settings|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private function getSettings(): ?Settings
    {
        $plugin = PluginHelper::getPlugin('healthcheck', 'frontenderrors');

        if (!\is_object($plugin)) {
            return null;
        }

        return Settings::fromParams(new Registry($plugin->params));
    }
}
