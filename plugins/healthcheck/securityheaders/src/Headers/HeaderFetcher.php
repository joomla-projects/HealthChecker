<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Securityheaders
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Securityheaders\Headers;

use Joomla\Http\Http;
use Joomla\Http\HttpFactory;
use Joomla\Http\Response;
use Joomla\Http\Transport\Curl;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Requests the site's own homepage and returns its response headers. The only class in this plugin that uses the network.
 *
 * @since  __DEPLOY_VERSION__
 */
final class HeaderFetcher
{
    public const MAX_REDIRECTS = 3;

    public const SUCCESS_TTL = 86400;

    public const FAILURE_TTL = 3600;

    public const DEFAULT_TIMEOUT = 5;

    public const REASON_NO_CURL = 'PLG_HEALTHCHECK_SECURITYHEADERS_REASON_NO_CURL';

    public const REASON_NO_HEADERS = 'PLG_HEALTHCHECK_SECURITYHEADERS_REASON_NO_HEADERS';

    public const REASON_REDIRECTS = 'PLG_HEALTHCHECK_SECURITYHEADERS_REASON_REDIRECTS';

    /**
     * Redirect status codes the fetcher follows
     *
     * @since  __DEPLOY_VERSION__
     */
    private const REDIRECTS = [301, 302, 303, 307, 308];

    /**
     * Constructor
     *
     * @param   HttpFactory  $httpFactory  Creates the HTTP client
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly HttpFactory $httpFactory)
    {
    }

    /**
     * Request the URL (HEAD, with one GET fallback when HEAD is refused with 405 or 501 or returns no headers), following up to three redirects, and return the final response's headers.
     *
     * @param   string   $url      The site root URL
     * @param   integer  $timeout  Seconds per request
     *
     * @return  array  ['ok', 'url', 'status', 'headers', 'reason', 'checkedAt']
     *
     * @since   __DEPLOY_VERSION__
     */
    public function fetch(string $url, int $timeout): array
    {
        $checkedAt = time();
        $start     = $url;

        if (!Curl::isSupported()) {
            return self::failure($start, self::REASON_NO_CURL, $checkedAt);
        }

        try {
            $http = $this->httpFactory->getHttp(
                [
                    'follow_location' => false,
                    'userAgent'       => 'Joomla Health Check',
                    // Certificate trust is the SSL Health Check's job; read the headers even from a local or self-signed certificate.
                    'transport.curl' => [
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_PROXY          => null,
                    ],
                ],
                'curl'
            );

            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $response = $this->request($http, $url, $timeout);
                $headers  = self::normalise($response->getHeaders());
                $status   = $response->getStatusCode();

                if (\in_array($status, self::REDIRECTS, true) && isset($headers['location'])) {
                    $next = self::resolve($url, $headers['location']);

                    if ($next !== null) {
                        $url = $next;

                        continue;
                    }
                }

                if ($headers === []) {
                    return self::failure($url, self::REASON_NO_HEADERS, $checkedAt);
                }

                return ['ok' => true, 'url' => $url, 'status' => $status, 'headers' => $headers, 'reason' => '', 'checkedAt' => $checkedAt];
            }

            return self::failure($url, self::REASON_REDIRECTS, $checkedAt);
        } catch (\Throwable $e) {
            $reason = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');

            return self::failure($start, $reason !== '' ? $reason : self::REASON_NO_HEADERS, $checkedAt);
        }
    }

    /**
     * Lower-case header names and join repeated values with ", ".
     *
     * @param   array  $raw  Header name to value or list of values
     *
     * @return  array<string, string>
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function normalise(array $raw): array
    {
        $headers = [];

        foreach ($raw as $name => $values) {
            $name = strtolower(trim((string) $name));

            if ($name === '') {
                continue;
            }

            $value = implode(', ', array_map(static fn ($v): string => trim((string) $v), (array) $values));

            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
        }

        return $headers;
    }

    /**
     * Resolve a Location header against the URL that was requested.
     *
     * @param   string  $base      The requested URL
     * @param   string  $location  The Location header
     *
     * @return  string|null  Absolute http(s) URL, or null if it cannot be followed
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function resolve(string $base, string $location): ?string
    {
        $location = trim($location);
        $parts    = parse_url($base);

        if ($location === '' || !\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return preg_match('#^https?://#i', $location) ? $location : null;
        }

        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $dir  = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . ($dir === '' ? '/' : $dir) . $location;
    }

    /**
     * A response result for a request that did not produce headers.
     *
     * @param   string   $url        The URL requested
     * @param   string   $reason     Language key or raw transport message
     * @param   integer  $checkedAt  Unix time of the attempt
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function failure(string $url, string $reason, int $checkedAt): array
    {
        return ['ok' => false, 'url' => $url, 'status' => 0, 'headers' => [], 'reason' => $reason, 'checkedAt' => $checkedAt];
    }

    /**
     * Whether a cached response is well formed and still fresh.
     *
     * @param   mixed    $cached  Cache content (false on a miss)
     * @param   integer  $now     Current Unix time
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function isUsable(mixed $cached, int $now): bool
    {
        if (
            !\is_array($cached)
            || !isset($cached['ok'], $cached['url'], $cached['status'], $cached['headers'], $cached['reason'], $cached['checkedAt'])
            || !\is_bool($cached['ok'])
            || !\is_string($cached['url'])
            || !\is_int($cached['status'])
            || !\is_array($cached['headers'])
            || !\is_string($cached['reason'])
            || !\is_int($cached['checkedAt'])
        ) {
            return false;
        }

        $age = $now - $cached['checkedAt'];

        return $age >= 0 && $age < ($cached['ok'] ? self::SUCCESS_TTL : self::FAILURE_TTL);
    }

    /**
     * Sanitise the timeout param.
     *
     * @param   mixed  $raw  Raw param value
     *
     * @return  integer  Seconds, 1 to 10
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function timeout(mixed $raw): int
    {
        return is_numeric($raw) ? min(10, max(1, (int) $raw)) : self::DEFAULT_TIMEOUT;
    }

    /**
     * HEAD the URL; fall back to one GET only if HEAD answered 405 or 501 or returned no headers. A HEAD that throws
     * (timeout, refused connection) propagates, so a dead host is waited on once, not twice.
     *
     * @param   Http     $http     Client
     * @param   string   $url      URL
     * @param   integer  $timeout  Seconds
     *
     * @return  Response
     *
     * @throws  \Throwable  When the HEAD request fails
     *
     * @since   __DEPLOY_VERSION__
     */
    private function request(Http $http, string $url, int $timeout): Response
    {
        $response = $http->head($url, [], $timeout);

        if (!\in_array($response->getStatusCode(), [405, 501], true) && $response->getHeaders() !== []) {
            return $response;
        }

        return $http->get($url, [], $timeout);
    }
}
