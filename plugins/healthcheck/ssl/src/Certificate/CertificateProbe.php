<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Ssl
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Ssl\Certificate;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Reads the TLS certificate the site's own host presents. The only class in this plugin that uses the network.
 *
 * @since  __DEPLOY_VERSION__
 */
final class CertificateProbe
{
    /**
     * Reason: the TLS handshake failed without any message.
     *
     * @since  __DEPLOY_VERSION__
     */
    public const REASON_HANDSHAKE = 'PLG_HEALTHCHECK_SSL_REASON_HANDSHAKE';

    /**
     * Reason: the site URL has no host name.
     *
     * @since  __DEPLOY_VERSION__
     */
    public const REASON_NO_HOST = 'PLG_HEALTHCHECK_SSL_REASON_NO_HOST';

    /**
     * Reason: the PHP OpenSSL extension is missing.
     *
     * @since  __DEPLOY_VERSION__
     */
    public const REASON_NO_OPENSSL = 'PLG_HEALTHCHECK_SSL_REASON_NO_OPENSSL';

    /**
     * Reason: the PHP stream socket functions are disabled.
     *
     * @since  __DEPLOY_VERSION__
     */
    public const REASON_NO_STREAMS = 'PLG_HEALTHCHECK_SSL_REASON_NO_STREAMS';

    /**
     * Reason: the probe threw unexpectedly.
     *
     * @since  __DEPLOY_VERSION__
     */
    public const REASON_PROBE_ERROR = 'PLG_HEALTHCHECK_SSL_REASON_PROBE_ERROR';

    /**
     * Reason: the certificate was received but could not be parsed.
     *
     * @since  __DEPLOY_VERSION__
     */
    public const REASON_UNREADABLE = 'PLG_HEALTHCHECK_SSL_REASON_UNREADABLE';

    /**
     * Derive the host, port and scheme to check from the site root URL.
     *
     * @param   string  $rootUrl  The site root URL, as from Uri::root()
     *
     * @return  array|null  ['https' => bool, 'host' => string, 'port' => int], or null without a host
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function target(string $rootUrl): ?array
    {
        $parts = parse_url($rootUrl);

        if (!\is_array($parts) || empty($parts['host'])) {
            return null;
        }

        return [
            'https' => strtolower($parts['scheme'] ?? '') === 'https',
            'host'  => strtolower(trim($parts['host'], '[]')),
            'port'  => isset($parts['port']) ? (int) $parts['port'] : 443,
        ];
    }

    /**
     * Read validity dates, issuer and subject name from a certificate.
     *
     * @param   \OpenSSLCertificate|string  $certificate  Certificate object or PEM string
     *
     * @return  array|null  ['validFrom' => int, 'validTo' => int, 'issuer' => string, 'subject' => string], or null if unreadable
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function parse(\OpenSSLCertificate|string $certificate): ?array
    {
        set_error_handler(static fn (): bool => true);

        try {
            $info = openssl_x509_parse($certificate);
        } finally {
            restore_error_handler();
        }

        if (!\is_array($info) || !isset($info['validFrom_time_t'], $info['validTo_time_t'])) {
            return null;
        }

        $issuer = $info['issuer']['O'] ?? $info['issuer']['CN'] ?? '';

        return [
            'validFrom' => (int) $info['validFrom_time_t'],
            'validTo'   => (int) $info['validTo_time_t'],
            'issuer'    => \is_array($issuer) ? implode(', ', $issuer) : (string) $issuer,
            'subject'   => self::subjectName($info),
        ];
    }

    /**
     * The name a certificate was issued for: the common name, else the first DNS name in the subject alternative names.
     *
     * @param   array  $info  An openssl_x509_parse() array
     *
     * @return  string  The name, or an empty string when there is none
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function subjectName(array $info): string
    {
        $common = $info['subject']['CN'] ?? '';

        foreach ((array) $common as $name) {
            if (\is_string($name) && $name !== '') {
                return $name;
            }
        }

        $alternatives = $info['extensions']['subjectAltName'] ?? '';

        if (\is_string($alternatives)) {
            foreach (explode(',', $alternatives) as $entry) {
                $entry = trim($entry);

                if (str_starts_with($entry, 'DNS:') && trim(substr($entry, 4)) !== '') {
                    return trim(substr($entry, 4));
                }
            }
        }

        return '';
    }

    /**
     * Turn a PHP stream warning into a one-line reason.
     *
     * @param   string  $message  Warning text
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function cleanReason(string $message): string
    {
        $message = preg_replace('/^\s*\w+\(\):\s*/', '', $message);

        return trim(preg_replace('/\s+/', ' ', $message));
    }

    /**
     * Clean a raw warning into a reason, falling back to the generic handshake reason when nothing is left.
     *
     * @param   string  $message  Warning text, possibly empty
     *
     * @return  string  Cleaned text, or a language key
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function reasonFrom(string $message): string
    {
        $reason = self::cleanReason($message);

        return $reason === '' ? self::REASON_HANDSHAKE : $reason;
    }

    /**
     * A probe result for a certificate that could not be read.
     *
     * @param   string   $reason     Why
     * @param   integer  $checkedAt  Unix time of the attempt
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function failure(string $reason, int $checkedAt): array
    {
        return ['ok' => false, 'trusted' => false, 'reason' => $reason, 'validFrom' => null, 'validTo' => null, 'issuer' => '', 'subject' => '', 'checkedAt' => $checkedAt];
    }

    /**
     * Connect to the host, complete a TLS handshake and read its certificate.
     * When the verified handshake connects but returns no certificate, it is retried once without verification
     * so the expiry date can still be reported. A connection that fails is not retried.
     *
     * @param   string   $host     Host name or IP address
     * @param   integer  $port     TCP port
     * @param   integer  $timeout  Seconds for the connect and for the handshake
     *
     * @return  array  ['ok', 'trusted', 'reason', 'validFrom', 'validTo', 'issuer', 'subject', 'checkedAt']
     *
     * @since   __DEPLOY_VERSION__
     */
    public function probe(string $host, int $port, int $timeout): array
    {
        $checkedAt = time();

        if (!\extension_loaded('openssl')) {
            return self::failure(self::REASON_NO_OPENSSL, $checkedAt);
        }

        foreach (['stream_socket_client', 'stream_socket_enable_crypto', 'stream_context_create', 'stream_context_get_options'] as $function) {
            if (!\function_exists($function)) {
                return self::failure(self::REASON_NO_STREAMS, $checkedAt);
            }
        }

        $verified = $this->handshake($host, $port, $timeout, true);

        if ($verified['certificate'] !== null) {
            return $this->result($verified['certificate'], true, '', $checkedAt);
        }

        $reason = self::reasonFrom($verified['error']);

        // A connection that failed would just fail again; only a connected but unverified handshake is retried.
        if (!$verified['connected']) {
            return self::failure($reason, $checkedAt);
        }

        $unverified = $this->handshake($host, $port, $timeout, false);

        if ($unverified['certificate'] === null) {
            return self::failure($reason, $checkedAt);
        }

        return $this->result($unverified['certificate'], false, $reason, $checkedAt);
    }

    /**
     * Build a probe result from a captured certificate.
     *
     * @param   \OpenSSLCertificate  $certificate  The peer certificate
     * @param   boolean              $trusted      Whether verification passed
     * @param   string               $reason       Why it is not trusted
     * @param   integer              $checkedAt    Unix time of the attempt
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private function result(\OpenSSLCertificate $certificate, bool $trusted, string $reason, int $checkedAt): array
    {
        $parsed = self::parse($certificate);

        if ($parsed === null) {
            return self::failure(self::REASON_UNREADABLE, $checkedAt);
        }

        return ['ok' => true, 'trusted' => $trusted, 'reason' => $reason] + $parsed + ['checkedAt' => $checkedAt];
    }

    /**
     * One TCP connect plus TLS handshake. Warnings are captured, never shown. No HTTP request is sent.
     *
     * @param   string   $host     Host name or IP address
     * @param   integer  $port     TCP port
     * @param   integer  $timeout  Seconds
     * @param   boolean  $verify   Whether to verify the peer and its name
     *
     * @return  array  ['connected' => bool, 'certificate' => ?\OpenSSLCertificate, 'error' => string]
     *
     * @since   __DEPLOY_VERSION__
     */
    private function handshake(string $host, int $port, int $timeout, bool $verify): array
    {
        $error = '';

        set_error_handler(static function (int $errno, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer'       => $verify,
                    'verify_peer_name'  => $verify,
                    'allow_self_signed' => !$verify,
                    'peer_name'         => $host,
                    'SNI_enabled'       => true,
                ],
            ]);

            $address = 'tcp://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
            $stream  = stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

            if ($stream === false) {
                return ['connected' => false, 'certificate' => null, 'error' => $errstr !== '' ? $errstr : $error];
            }

            stream_set_timeout($stream, $timeout);

            $certificate = null;

            if (stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) === true) {
                $certificate = stream_context_get_options($stream)['ssl']['peer_certificate'] ?? null;
            }

            fclose($stream);

            return ['connected' => true, 'certificate' => $certificate, 'error' => $error];
        } finally {
            restore_error_handler();
        }
    }
}
