<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Securityheaders
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Securityheaders\Headers;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Scores HTTP security headers with fixed weights. Pure: no I/O.
 *
 * @since  __DEPLOY_VERSION__
 */
final class HeaderScore
{
    /**
     * Minimum HSTS max-age for full marks: 180 days
     *
     * @since  __DEPLOY_VERSION__
     */
    public const HSTS_MIN_AGE = 15552000;

    /**
     * Every Referrer-Policy token browsers recognise
     *
     * @since  __DEPLOY_VERSION__
     */
    private const REFERRER_TOKENS = [
        'no-referrer', 'no-referrer-when-downgrade', 'origin', 'origin-when-cross-origin',
        'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'unsafe-url',
    ];

    /**
     * Referrer-Policy values that do not leak full URLs to other sites
     *
     * @since  __DEPLOY_VERSION__
     */
    private const REFERRER_SAFE = [
        'no-referrer', 'origin', 'origin-when-cross-origin', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin',
    ];

    /**
     * Score a set of response headers.
     *
     * @param   array    $headers  Lower-cased header names to values, repeated headers joined with ", "
     * @param   boolean  $https    Whether the scored response came from an https URL
     *
     * @return  array  ['score' => int, 'status' => string, 'items' => list<array>]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function evaluate(array $headers, bool $https): array
    {
        $get = static fn (string $name): string => trim((string) ($headers[$name] ?? ''));

        $items = [
            self::hsts($get('strict-transport-security'), $https),
            self::csp($get('content-security-policy'), $get('content-security-policy-report-only')),
            self::frame($get('x-frame-options'), $get('content-security-policy')),
            self::single('nosniff', 15, $get('x-content-type-options'), ['nosniff']),
            self::referrer($get('referrer-policy')),
            self::present('permissions', 5, $get('permissions-policy')),
            self::present('coop', 5, $get('cross-origin-opener-policy')),
            self::leak($get('server'), $get('x-powered-by')),
        ];

        $score = max(0, min(100, array_sum(array_column($items, 'points'))));

        return [
            'score'  => $score,
            'status' => $score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'error'),
            'items'  => $items,
        ];
    }

    /**
     * Strict-Transport-Security: full marks from 180 days, partial below, nothing on plain http.
     *
     * @param   string   $value  Header value
     * @param   boolean  $https  Whether the response came over https
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function hsts(string $value, bool $https): array
    {
        if (!$https) {
            return self::item('hsts', 0, 25, $value, 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_HSTS_HTTP');
        }

        // Browsers process only the first Strict-Transport-Security header (RFC 6797 section 8.1); repeats are joined with a comma.
        $value = explode(',', $value, 2)[0];
        $age   = preg_match('/(?:^|;)\s*max-age\s*=\s*"?(\d+)"?\s*(?:;|$)/i', $value, $matches) ? (int) $matches[1] : 0;

        return self::item('hsts', $age >= self::HSTS_MIN_AGE ? 25 : ($age > 0 ? 10 : 0), 25, $value);
    }

    /**
     * Content-Security-Policy: enforced is full marks, report-only is partial.
     *
     * @param   string  $enforced    Content-Security-Policy value
     * @param   string  $reportOnly  Content-Security-Policy-Report-Only value
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function csp(string $enforced, string $reportOnly): array
    {
        if ($enforced !== '') {
            return self::item('csp', 25, 25, $enforced);
        }

        return self::item('csp', $reportOnly !== '' ? 10 : 0, 25, $reportOnly);
    }

    /**
     * Frame protection: X-Frame-Options DENY or SAMEORIGIN, or an enforced CSP frame-ancestors directive.
     *
     * @param   string  $xfo  X-Frame-Options value
     * @param   string  $csp  Content-Security-Policy value
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function frame(string $xfo, string $csp): array
    {
        $item = self::single('frame', 15, $xfo, ['deny', 'sameorigin']);

        if (
            $item['points'] === 0
            && preg_match('/(?:^|[;,])\s*frame-ancestors(?=\s|;|,|$)([^;,]*)/i', $csp, $matches)
            && self::restrictsFraming(trim($matches[1]))
        ) {
            return self::item('frame', 15, 15, 'frame-ancestors ' . trim($matches[1]));
        }

        return $item;
    }

    /**
     * Whether a frame-ancestors source list limits who can embed the page: not empty, not "*", not only bare schemes.
     *
     * @param   string  $sources  The directive's source list
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function restrictsFraming(string $sources): bool
    {
        foreach (preg_split('/\s+/', $sources, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $source) {
            if ($source !== '*' && !preg_match('/^[a-z][a-z0-9+.-]*:$/i', $source)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Referrer-Policy: the last recognised token is the one browsers apply.
     *
     * @param   string  $value  Header value
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function referrer(string $value): array
    {
        $effective = '';

        foreach (self::tokens($value) as $token) {
            if (\in_array($token, self::REFERRER_TOKENS, true)) {
                $effective = $token;
            }
        }

        return self::item('referrer', \in_array($effective, self::REFERRER_SAFE, true) ? 10 : 0, 10, $value);
    }

    /**
     * A header that must carry exactly one of the accepted values (duplicates of the same value are fine).
     *
     * @param   string    $key       Item key
     * @param   integer   $max       Points for an accepted value
     * @param   string    $value     Header value
     * @param   string[]  $accepted  Accepted lower-case values
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function single(string $key, int $max, string $value, array $accepted): array
    {
        $tokens = array_values(array_unique(self::tokens($value)));
        $ok     = \count($tokens) === 1 && \in_array($tokens[0], $accepted, true);

        return self::item($key, $ok ? $max : 0, $max, $value);
    }

    /**
     * A header that only needs to be present.
     *
     * @param   string   $key    Item key
     * @param   integer  $max    Points when present
     * @param   string   $value  Header value
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function present(string $key, int $max, string $value): array
    {
        return self::item($key, $value !== '' ? $max : 0, $max, $value);
    }

    /**
     * Version numbers in Server or X-Powered-By cost 5 points.
     *
     * @param   string  $server     Server value
     * @param   string  $poweredBy  X-Powered-By value
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function leak(string $server, string $poweredBy): array
    {
        $leaks = array_values(array_filter([$server, $poweredBy], static fn (string $value): bool => (bool) preg_match('/\d+\.\d+/', $value)));

        return self::item('leak', $leaks === [] ? 0 : -5, 0, implode(', ', $leaks));
    }

    /**
     * Comma-separated, trimmed, lower-case tokens.
     *
     * @param   string  $value  Header value
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function tokens(string $value): array
    {
        return array_values(array_filter(array_map(static fn (string $token): string => strtolower(trim($token)), explode(',', $value)), 'strlen'));
    }

    /**
     * Build one scored item; status and fix key follow from the points.
     *
     * @param   string   $key     Item key
     * @param   integer  $points  Points earned
     * @param   integer  $max     Points available (0 for the leak penalty)
     * @param   string   $value   Header value as sent
     * @param   ?string  $fix     Fix key override
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function item(string $key, int $points, int $max, string $value, ?string $fix = null): array
    {
        if ($max === 0) {
            $status = $points < 0 ? 'error' : 'success';
        } else {
            $status = $points >= $max ? 'success' : ($points > 0 ? 'warning' : 'error');
        }

        if ($fix === null && $status !== 'success') {
            $fix = 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_' . strtoupper($key);
        }

        return ['key' => $key, 'points' => $points, 'max' => $max, 'status' => $status, 'value' => $value, 'fix' => $fix];
    }
}
