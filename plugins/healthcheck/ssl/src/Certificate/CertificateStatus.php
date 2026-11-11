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
 * Status rules for the site's TLS certificate. Pure: no I/O, the current time is passed in.
 *
 * @since  __DEPLOY_VERSION__
 */
final class CertificateStatus
{
    public const SUCCESS = 'success';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    public const NONE = 'none';

    public const DEFAULT_WARNING_DAYS = 14;

    public const DEFAULT_ERROR_DAYS = 7;

    public const DEFAULT_TIMEOUT = 3;

    /**
     * Seconds a successful probe result is reused
     *
     * @since  __DEPLOY_VERSION__
     */
    public const SUCCESS_TTL = 86400;

    /**
     * Seconds a failed probe result is reused, so an unreachable host does not stall every dashboard load
     *
     * @since  __DEPLOY_VERSION__
     */
    public const FAILURE_TTL = 3600;

    /**
     * Sanitise the threshold params. If the error threshold is at or above the warning threshold, the warning window is empty.
     *
     * @param   mixed  $warning  Raw warning_days param
     * @param   mixed  $error    Raw error_days param
     *
     * @return  array  [int warningDays, int errorDays]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function thresholds(mixed $warning, mixed $error): array
    {
        $warning = is_numeric($warning) && (int) $warning >= 1 ? (int) $warning : self::DEFAULT_WARNING_DAYS;
        $error   = is_numeric($error) && (int) $error >= 0 ? (int) $error : self::DEFAULT_ERROR_DAYS;

        return [max($warning, $error), $error];
    }

    /**
     * Sanitise the timeout param.
     *
     * @param   mixed  $timeout  Raw timeout param
     *
     * @return  integer  Seconds, 1 to 10
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function timeout(mixed $timeout): int
    {
        return is_numeric($timeout) ? min(10, max(1, (int) $timeout)) : self::DEFAULT_TIMEOUT;
    }

    /**
     * Whether a cached probe result is well formed and still fresh.
     *
     * @param   mixed    $cached  What the cache returned (false on a miss)
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
            || !isset($cached['ok'], $cached['trusted'], $cached['reason'], $cached['issuer'], $cached['subject'], $cached['checkedAt'])
            || !\array_key_exists('validFrom', $cached)
            || !\array_key_exists('validTo', $cached)
            || !\is_bool($cached['ok'])
            || !\is_bool($cached['trusted'])
            || !\is_string($cached['reason'])
            || !\is_string($cached['issuer'])
            || !\is_string($cached['subject'])
            || !\is_int($cached['checkedAt'])
        ) {
            return false;
        }

        if ($cached['ok'] && (!\is_int($cached['validFrom']) || !\is_int($cached['validTo']))) {
            return false;
        }

        $age = $now - $cached['checkedAt'];

        return $age >= 0 && $age < ($cached['ok'] ? self::SUCCESS_TTL : self::FAILURE_TTL);
    }

    /**
     * Map a probe result to a status, message key and day count.
     *
     * @param   ?array   $probe        Probe result, or null when the site is not served over HTTPS
     * @param   integer  $now          Current Unix time
     * @param   integer  $warningDays  Warn at or below this many days
     * @param   integer  $errorDays    Error at or below this many days
     *
     * @return  array  ['status' => string, 'key' => string, 'days' => ?int, 'date' => ?int, 'plural' => bool]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function evaluate(?array $probe, int $now, int $warningDays, int $errorDays): array
    {
        if ($probe === null) {
            return self::result(self::WARNING, 'NO_HTTPS');
        }

        if (empty($probe['ok'])) {
            return self::result(self::NONE, 'UNCHECKED');
        }

        $validTo   = (int) $probe['validTo'];
        $validFrom = (int) $probe['validFrom'];

        if ($validTo < $now) {
            return self::result(self::ERROR, 'EXPIRED', null, $validTo);
        }

        if ($validFrom > $now) {
            return self::result(self::ERROR, 'NOT_YET_VALID', null, $validFrom);
        }

        $days = intdiv($validTo - $now, 86400);

        if (empty($probe['trusted'])) {
            return self::result(self::ERROR, 'UNTRUSTED', $days, $validTo);
        }

        if ($days <= $errorDays) {
            return self::result(self::ERROR, 'EXPIRES_N', $days, $validTo, true);
        }

        if ($days <= $warningDays) {
            return self::result(self::WARNING, 'RENEW_SOON_N', $days, $validTo, true);
        }

        return self::result(self::SUCCESS, 'VALID_N', $days, $validTo, true);
    }

    /**
     * Build an evaluate() result.
     *
     * @param   string   $status  One of the status constants
     * @param   string   $suffix  Message key suffix after PLG_HEALTHCHECK_SSL_STATUS_
     * @param   ?int     $days    Days left
     * @param   ?int     $date    Timestamp shown in the message
     * @param   boolean  $plural  Whether the key is used with Text::plural()
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function result(string $status, string $suffix, ?int $days = null, ?int $date = null, bool $plural = false): array
    {
        return ['status' => $status, 'key' => 'PLG_HEALTHCHECK_SSL_STATUS_' . $suffix, 'days' => $days, 'date' => $date, 'plural' => $plural];
    }
}
