<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Frontenderrors\Report;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Validates, scrubs and truncates a browser report payload.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ReportNormaliser
{
    private const TYPES = ['error', 'rejection', 'resource', 'csp', 'console'];

    private const MAX_REPORTS = 10;

    private const MAX_COUNT = 50;

    private const MAX_MESSAGE = 500;

    private const MAX_FILE = 255;

    private const MAX_PAGE = 255;

    private const MAX_STACK = 2000;

    /** Largest line or column number: the unsigned 32 bit limit of the MySQL columns. */
    private const MAX_POSITION = 4294967295;

    /**
     * Normalise a decoded payload.
     *
     * @param   mixed   $payload   Decoded JSON {page, reports}
     * @param   string  $siteRoot  Site root URL (Uri::root())
     *
     * @return  array  List of normalised reports, empty when the payload is invalid
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function normalise(mixed $payload, string $siteRoot): array
    {
        if (!\is_array($payload) || !isset($payload['page']) || !\is_string($payload['page'])) {
            return [];
        }

        $reports = $payload['reports'] ?? null;

        if (!\is_array($reports) || \count($reports) > self::MAX_REPORTS) {
            return [];
        }

        $page = self::normalisePage($payload['page']);

        if ($page === null) {
            return [];
        }

        $origin = self::origin($siteRoot);
        $result = [];

        foreach ($reports as $report) {
            if (!\is_array($report)) {
                continue;
            }

            $type = $report['type'] ?? null;

            if (!\is_string($type) || !\in_array($type, self::TYPES, true)) {
                continue;
            }

            $message = self::cut(self::scrub(self::str($report['message'] ?? '')), self::MAX_MESSAGE);
            $file    = self::cut(self::scrub(self::normaliseUrl(self::str($report['file'] ?? ''), $origin)), self::MAX_FILE);
            $stack   = self::cut(self::scrub(self::str($report['stack'] ?? '')), self::MAX_STACK);
            $line    = min(self::MAX_POSITION, max(0, (int) ($report['line'] ?? 0)));
            $col     = min(self::MAX_POSITION, max(0, (int) ($report['col'] ?? 0)));
            $count   = max(1, min(self::MAX_COUNT, (int) ($report['count'] ?? 1)));

            $result[] = [
                'fingerprint' => self::fingerprint($type, $message, $file, $line, $col),
                'type'        => $type,
                'message'     => $message,
                'file'        => $file,
                'line'        => $line,
                'col'         => $col,
                'stack'       => $stack,
                'page'        => $page,
                'count'       => $count,
            ];
        }

        return $result;
    }

    /**
     * Remove personal data and identifiers from a text.
     *
     * @param   string  $text  The text
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function scrub(string $text): string
    {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $text) ?? $text;
        $text = preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
            '[redacted]',
            $text
        ) ?? $text;
        $text = preg_replace_callback(
            '/[A-Za-z0-9+_]{16,}={0,2}/',
            static fn (array $m): string => preg_match('/\d/', $m[0]) ? '[redacted]' : $m[0],
            $text
        ) ?? $text;

        return preg_replace('/\d{6,}/', '[redacted]', $text) ?? $text;
    }

    /**
     * Fingerprint of an error.
     *
     * @param   string   $type     Report type
     * @param   string   $message  Message
     * @param   string   $file     File
     * @param   integer  $line     Line
     * @param   integer  $col      Column
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function fingerprint(string $type, string $message, string $file, int $line, int $col): string
    {
        return sha1(implode("\n", [$type, $message, $file, $line, $col]));
    }

    /**
     * Validate and clean the page path.
     *
     * @param   string  $page  Raw page value
     *
     * @return  string|null
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function normalisePage(string $page): ?string
    {
        if (!str_starts_with($page, '/') || str_starts_with($page, '//') || str_contains($page, '\\')) {
            return null;
        }

        $page = self::stripQueryAndHash($page);

        return self::cut(self::scrub($page), self::MAX_PAGE);
    }

    /**
     * Reduce a file URL: no query or hash, same-origin URLs become paths.
     *
     * @param   string  $url     The URL
     * @param   string  $origin  Site origin (scheme://host[:port])
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function normaliseUrl(string $url, string $origin): string
    {
        $url = self::stripQueryAndHash($url);

        if ($origin !== '' && strncasecmp($url, $origin . '/', \strlen($origin) + 1) === 0) {
            return substr($url, \strlen($origin));
        }

        if (strcasecmp($url, $origin) === 0 && $origin !== '') {
            return '/';
        }

        return $url;
    }

    /**
     * Scheme and host of the site root.
     *
     * @param   string  $siteRoot  Site root URL
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function origin(string $siteRoot): string
    {
        $parts = parse_url(rtrim($siteRoot, '/'));

        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Drop everything from the first ? or #.
     *
     * @param   string  $url  The URL
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function stripQueryAndHash(string $url): string
    {
        return preg_split('/[?#]/', $url, 2)[0];
    }

    /**
     * Cast a scalar to string, anything else to empty.
     *
     * @param   mixed  $value  The value
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * Truncate to a number of characters.
     *
     * @param   string   $text    The text
     * @param   integer  $length  Maximum characters
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function cut(string $text, int $length): string
    {
        return mb_substr($text, 0, $length, 'UTF-8');
    }
}
