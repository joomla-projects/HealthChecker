<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.SslCertificate
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\SslCertificate\Extension;

use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;
use Joomla\Module\Healthcheck\Administrator\Event\HealthChecksEvent;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Plugin to check how long the SSL certificate of the website remains valid.
 *
 * The network connection needed to read the certificate is slow, so the dashboard tile is rendered
 * without it and filled afterwards by the asynchronous Health Check script, which calls
 * onAjaxSslcertificate() through com_ajax.
 *
 * @since    __DEPLOY_VERSION__
 */
final class SslCertificate extends CMSPlugin implements SubscriberInterface
{
    /**
     * The URL of the page the tile links to.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const LINK = 'index.php?option=com_admin&view=sysinfo';

    /**
     * The number of seconds to wait for the connection to the web server.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const TIMEOUT = 10;

    /**
     * The number of seconds in a day.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    private const SECONDS_PER_DAY = 86400;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since    __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onHealthcheckGetIcons' => 'onHealthcheckGetIcons',
            'onAjaxSslcertificate'  => 'onAjaxSslcertificate',
        ];
    }

    /**
     * Adds the tile for the SSL certificate to the list of icons.
     *
     * The days left and the status are not known yet: the tile carries the address (ajaxurl) from which
     * the asynchronous Health Check script fetches them. Only checks which can be answered without a
     * network connection are answered here.
     *
     * @param   HealthChecksEvent  $event  The health-check event object.
     *
     * @return  void
     *
     * @since    __DEPLOY_VERSION__
     */
    public function onHealthcheckGetIcons(HealthChecksEvent $event): void
    {
        if ($event->getContext() !== $this->params->get('context', 'general')) {
            return;
        }

        $this->loadLanguage();

        $icon = [
            'link' => self::LINK,
            'icon' => 'fas fa-certificate',
            'id'   => 'plg_healthcheck_sslcertificate',
        ];

        $problem = $this->getPreconditionProblem();

        if ($problem !== null) {
            // There is nothing to fetch: show the reason right away.
            $icon['amount'] = '-';
            $icon['text']   = htmlspecialchars(Text::_($problem), ENT_QUOTES, 'UTF-8');
            $icon['status'] = 'warning';
        } else {
            $icon['ajaxurl'] = 'index.php?option=com_ajax&amp;group=healthcheck&amp;plugin=sslcertificate&amp;format=json';
            $icon['text']    = htmlspecialchars(Text::_('PLG_HEALTHCHECK_SSLCERTIFICATE_TILE_TEXT'), ENT_QUOTES, 'UTF-8');
        }

        $result   = $event->getArgument('result', []);
        $result[] = [$icon];

        $event->setArgument('result', $result);
    }

    /**
     * Reads the certificate and returns the days left and the status for the tile.
     *
     * Called by the asynchronous Health Check script through com_ajax
     * (index.php?option=com_ajax&group=healthcheck&plugin=sslcertificate&format=json).
     *
     * @param   AjaxEvent  $event  The event object.
     *
     * @return  void
     *
     * @throws  \RuntimeException  If the user is not allowed to log in to the administrator.
     *
     * @since    __DEPLOY_VERSION__
     */
    public function onAjaxSslcertificate(AjaxEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator') || !$app->getIdentity()->authorise('core.login.admin')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->loadLanguage();

        $problem     = $this->getPreconditionProblem();
        $certificate = $problem === null ? $this->readCertificate() : null;

        if ($certificate === null) {
            // The tile script needs a number to apply the status, so a warning is reported with zero days.
            $event->addResult([
                'amount'  => 0,
                'status'  => 'warning',
                'message' => Text::_($problem ?? 'PLG_HEALTHCHECK_SSLCERTIFICATE_CONNECTION_FAILED'),
            ]);

            return;
        }

        $daysLeft = (int) floor(($certificate['validTo'] - time()) / self::SECONDS_PER_DAY);
        $status   = $this->getStatus($daysLeft);

        $event->addResult([
            'amount' => $daysLeft,
            'status' => $status,
            'items'  => [
                [
                    'title' => Text::sprintf(
                        'PLG_HEALTHCHECK_SSLCERTIFICATE_ISSUER',
                        $certificate['issuer'] ?: Text::_('PLG_HEALTHCHECK_SSLCERTIFICATE_ISSUER_UNKNOWN')
                    ),
                ],
                [
                    'title' => Text::sprintf(
                        $daysLeft < 0 ? 'PLG_HEALTHCHECK_SSLCERTIFICATE_EXPIRED_ON' : 'PLG_HEALTHCHECK_SSLCERTIFICATE_EXPIRES_ON',
                        HTMLHelper::_('date', $certificate['validTo'], Text::_('DATE_FORMAT_LC3'))
                    ),
                ],
                [
                    'title' => Text::_('PLG_HEALTHCHECK_SSLCERTIFICATE_SYSTEM_INFORMATION'),
                    'link'  => self::LINK,
                ],
            ],
        ]);
    }

    /**
     * Returns the status for the given number of days left.
     *
     * The Health Check script only knows the statuses success, warning and error (shown as danger).
     *
     * @param   integer  $daysLeft  The number of days until the certificate expires, negative once expired.
     *
     * @return  string  One of success, warning or error.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getStatus(int $daysLeft): string
    {
        $dangerDays  = max(1, (int) $this->params->get('danger_days', 7));
        $warningDays = max($dangerDays, (int) $this->params->get('warning_days', 30));

        if ($daysLeft <= $dangerDays) {
            return 'error';
        }

        if ($daysLeft <= $warningDays) {
            return 'warning';
        }

        return 'success';
    }

    /**
     * Returns the language key of the reason why the certificate can not be checked at all, if there is one.
     *
     * @return  string|null  The language key, or null if the check can be done.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function getPreconditionProblem(): ?string
    {
        if (!\extension_loaded('openssl') || !\function_exists('openssl_x509_parse')) {
            return 'PLG_HEALTHCHECK_SSLCERTIFICATE_NO_OPENSSL';
        }

        $uri = new Uri(Uri::root());

        if ($uri->getScheme() !== 'https' || $uri->getHost() === '') {
            return 'PLG_HEALTHCHECK_SSLCERTIFICATE_NOT_HTTPS';
        }

        return null;
    }

    /**
     * Connects to the web server of this site and reads its certificate.
     *
     * The certificate is not verified, so that an expired or mismatching certificate can be read and reported.
     *
     * @return  array|null  The issuer and the expiry timestamp (validTo) of the certificate, or null on failure.
     *
     * @since    __DEPLOY_VERSION__
     */
    protected function readCertificate(): ?array
    {
        $uri  = new Uri(Uri::root());
        $port = $uri->getPort() ?: 443;

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ],
        ]);

        // A failing connection raises a PHP warning: turn it off while connecting, the result is checked below.
        set_error_handler(static fn (): bool => true);

        try {
            $client = stream_socket_client(
                'ssl://' . $uri->getHost() . ':' . $port,
                $errorNumber,
                $errorMessage,
                self::TIMEOUT,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($client === false) {
                return null;
            }

            $options = stream_context_get_options($client);

            fclose($client);

            $parsed = empty($options['ssl']['peer_certificate']) ? false : openssl_x509_parse($options['ssl']['peer_certificate']);
        } finally {
            restore_error_handler();
        }

        if (!\is_array($parsed) || !isset($parsed['validTo_time_t'])) {
            return null;
        }

        $issuer = $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? '';

        if (\is_array($issuer)) {
            $issuer = (string) reset($issuer);
        }

        return [
            'issuer'  => (string) $issuer,
            'validTo' => (int) $parsed['validTo_time_t'],
        ];
    }
}
