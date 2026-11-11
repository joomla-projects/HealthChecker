<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Ssl
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Ssl\Extension;

use Joomla\CMS\Cache\CacheControllerFactoryAwareTrait;
use Joomla\CMS\Cache\Controller\OutputController;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;
use Joomla\Plugin\Healthcheck\Ssl\Certificate\CertificateProbe;
use Joomla\Plugin\Healthcheck\Ssl\Certificate\CertificateStatus;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Health Check plugin showing the expiry and trust of the site's TLS certificate
 *
 * @since  __DEPLOY_VERSION__
 */
final class Ssl extends CMSPlugin implements SubscriberInterface
{
    use CacheControllerFactoryAwareTrait;

    /**
     * Cache group of the probe results
     *
     * @since  __DEPLOY_VERSION__
     */
    private const CACHE_GROUP = 'plg_healthcheck_ssl';

    /**
     * Constructor
     *
     * @param   array             $config  The plugin configuration
     * @param   CertificateProbe  $probe   Reads the certificate from the site's host
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(array $config, private readonly CertificateProbe $probe)
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
            'onHealthcheckGetIcons' => 'onHealthcheckGetIcons',
            'onAjaxSsl'             => 'onAjaxSsl',
        ];
    }

    /**
     * Adds the SSL Certificate icon.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onHealthcheckGetIcons(HealthChecksEvent $event): void
    {
        if (
            $event->getContext() !== $this->params->get('context', 'security')
            || !$this->getApplication()->getIdentity()->authorise('core.admin')
        ) {
            return;
        }

        $this->loadLanguage();

        $now    = time();
        $target = CertificateProbe::target(Uri::root());

        if ($target === null) {
            $probe = CertificateProbe::failure(CertificateProbe::REASON_NO_HOST, $now);
        } else {
            $probe = $target['https'] ? $this->lookup($target['host'], $target['port'], $now) : null;
        }

        [$warningDays, $errorDays] = CertificateStatus::thresholds($this->params->get('warning_days'), $this->params->get('error_days'));

        $result = CertificateStatus::evaluate($probe, $now, $warningDays, $errorDays);
        $open   = \in_array($result['key'], ['PLG_HEALTHCHECK_SSL_STATUS_NO_HTTPS', 'PLG_HEALTHCHECK_SSL_STATUS_UNTRUSTED'], true);
        $return = base64_encode(Uri::getInstance()->toString());

        $icon = [
            'link'    => 'index.php?option=com_ajax&group=healthcheck&plugin=ssl&format=raw&' . Session::getFormToken() . '=1&return=' . urlencode($return),
            'icon'    => $open ? 'icon-unlock' : 'icon-lock',
            'section' => Text::_('PLG_HEALTHCHECK_SSL_SECTION'),
            'name'    => 'PLG_HEALTHCHECK_SSL_ICON_NAME',
            'text'    => $this->message($result),
            'title'   => $this->title($probe),
            'id'      => 'plg_healthcheck_ssl',
        ];

        if ($result['days'] !== null) {
            $icon['amount'] = $result['days'];
        }

        if ($result['status'] !== CertificateStatus::NONE) {
            $icon['status'] = $result['status'];
        }

        $results   = $event->getArgument('result', []);
        $results[] = [$icon];

        $event->setArgument('result', $results);
    }

    /**
     * Forget the stored result so the next dashboard load checks the certificate again.
     *
     * @param   AjaxEvent  $event  The Ajax event
     *
     * @return  void
     *
     * @throws  \RuntimeException  On a missing token or permission
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onAjaxSsl(AjaxEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator') || !Session::checkToken('get') || !$app->getIdentity()->authorise('core.admin')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $target = CertificateProbe::target(Uri::root());

        if ($target !== null) {
            try {
                $this->cache()->remove($target['host'] . ':' . $target['port']);
            } catch (\Throwable $e) {
                Log::add('SSL Health Check: could not clear the stored result: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
            }
        }

        $return = base64_decode($app->getInput()->getBase64('return', ''));

        $app->redirect(Uri::isInternal($return) ? $return : 'index.php?option=com_cpanel&view=cpanel&dashboard=healthcheck');
    }

    /**
     * Get a fresh cached probe result, or probe and store it.
     *
     * @param   string   $host  Host name
     * @param   integer  $port  Port
     * @param   integer  $now   Current Unix time
     *
     * @return  array  Probe result
     *
     * @since   __DEPLOY_VERSION__
     */
    private function lookup(string $host, int $port, int $now): array
    {
        $id = $host . ':' . $port;

        try {
            $cached = $this->cache()->get($id);
        } catch (\Throwable $e) {
            $cached = false;
        }

        if (CertificateStatus::isUsable($cached, $now)) {
            return $cached;
        }

        try {
            $probe = $this->probe->probe($host, $port, CertificateStatus::timeout($this->params->get('timeout')));
        } catch (\Throwable $e) {
            Log::add('SSL Health Check: the probe failed: ' . $e->getMessage(), Log::WARNING, 'healthcheck');

            $probe = CertificateProbe::failure(CertificateProbe::REASON_PROBE_ERROR, $now);
        }

        try {
            $this->cache()->store($probe, $id);
        } catch (\Throwable $e) {
            Log::add('SSL Health Check: could not store the result: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
        }

        return $probe;
    }

    /**
     * The status message, followed by the site's own message for this status if one is set in a language override.
     *
     * @param   array  $result  CertificateStatus::evaluate() result
     *
     * @return  string  HTML from trusted language strings only
     *
     * @since   __DEPLOY_VERSION__
     */
    private function message(array $result): string
    {
        $date = $result['date'] === null ? '' : HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $result['date']), Text::_('DATE_FORMAT_LC4'));

        if ($result['plural']) {
            $text = Text::plural($result['key'], $result['days'], $date);
        } elseif ($result['date'] !== null) {
            $text = Text::sprintf($result['key'], $date);
        } else {
            $text = Text::_($result['key']);
        }

        $customKey = 'PLG_HEALTHCHECK_SSL_CUSTOM_' . strtoupper($result['status']);
        $custom    = trim(Text::_($customKey));

        if ($custom !== '' && $custom !== $customKey) {
            $text .= '<span class="d-block">' . $custom . '</span>';
        }

        return $text;
    }

    /**
     * Tooltip: the name the certificate was issued for, the issuer, why the certificate is not trusted or could not be read, and when it was checked.
     *
     * @param   ?array  $probe  Probe result, or null when the site is not served over HTTPS
     *
     * @return  string  Plain text (the layout escapes it)
     *
     * @since   __DEPLOY_VERSION__
     */
    private function title(?array $probe): string
    {
        if ($probe === null) {
            return Text::_('PLG_HEALTHCHECK_SSL_TITLE_NO_HTTPS');
        }

        $parts = [];

        if ($probe['subject'] !== '') {
            $parts[] = Text::sprintf('PLG_HEALTHCHECK_SSL_TITLE_SUBJECT', $probe['subject']);
        }

        if ($probe['issuer'] !== '') {
            $parts[] = Text::sprintf('PLG_HEALTHCHECK_SSL_TITLE_ISSUER', $probe['issuer']);
        }

        if ($probe['reason'] !== '') {
            $reason  = str_starts_with($probe['reason'], 'PLG_HEALTHCHECK_SSL_REASON_') ? Text::_($probe['reason']) : $probe['reason'];
            $parts[] = Text::sprintf('PLG_HEALTHCHECK_SSL_TITLE_REASON', $reason);
        }

        $parts[] = Text::sprintf(
            'PLG_HEALTHCHECK_SSL_TITLE_CHECKED',
            HTMLHelper::_('date', gmdate('Y-m-d H:i:s', $probe['checkedAt']), Text::_('DATE_FORMAT_LC2'))
        );

        return implode(' ', $parts);
    }

    /**
     * Get the cache controller holding probe results.
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
