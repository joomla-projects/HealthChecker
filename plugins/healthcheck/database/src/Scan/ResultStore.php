<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Scan;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Cache\Controller\OutputController;
use Joomla\CMS\Log\Log;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Holds the scan results in a cache group that works even when global caching is off.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ResultStore
{
    /**
     * Cache group of the stored results.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    public const CACHE_GROUP = 'plg_healthcheck_database';

    /**
     * Minutes. Freshness is decided by the refresh_hours parameter, not by expiry.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    public const CACHE_LIFETIME = 10080;

    /**
     * Constructor
     *
     * @param   CacheControllerFactoryInterface  $factory  Cache controller factory
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly CacheControllerFactoryInterface $factory)
    {
    }

    /**
     * Read a stored value.
     *
     * @param   string  $key  `unused` or `zerodates`
     *
     * @return  ?array  The value, or null when nothing usable is stored
     *
     * @since   __DEPLOY_VERSION__
     */
    public function get(string $key): ?array
    {
        try {
            $value = $this->cache()->get($key);
        } catch (\Throwable $e) {
            Log::add('Database Health Check: could not read the stored result: ' . $e->getMessage(), Log::WARNING, 'healthcheck');

            return null;
        }

        return \is_array($value) ? $value : null;
    }

    /**
     * Store a value.
     *
     * @param   string  $key    `unused` or `zerodates`
     * @param   array   $value  The value
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function put(string $key, array $value): void
    {
        try {
            $this->cache()->store($value, $key);
        } catch (\Throwable $e) {
            Log::add('Database Health Check: could not store the result: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
        }
    }

    /**
     * Remove a stored value.
     *
     * @param   string  $key  `unused` or `zerodates`
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function remove(string $key): void
    {
        try {
            $this->cache()->remove($key);
        } catch (\Throwable $e) {
            Log::add('Database Health Check: could not clear the stored result: ' . $e->getMessage(), Log::WARNING, 'healthcheck');
        }
    }

    /**
     * Whether a result is older than the refresh interval.
     *
     * @param   ?string  $updated       When it was completed (Y-m-d H:i:s, UTC)
     * @param   integer  $refreshHours  Hours a result stays fresh
     * @param   string   $now           Current time (Y-m-d H:i:s, UTC)
     *
     * @return  boolean  True when stale, missing or unparsable
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function isStale(?string $updated, int $refreshHours, string $now): bool
    {
        if ($updated === null) {
            return true;
        }

        $utc  = new \DateTimeZone('UTC');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $updated, $utc);
        $to   = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $now, $utc);

        if ($from === false || $to === false) {
            return true;
        }

        return $to->getTimestamp() - $from->getTimestamp() >= $refreshHours * 3600;
    }

    /**
     * The output cache controller, with caching forced on.
     *
     * @return  OutputController
     *
     * @since   __DEPLOY_VERSION__
     */
    private function cache(): OutputController
    {
        return $this->factory->createCacheController(
            'output',
            ['defaultgroup' => self::CACHE_GROUP, 'caching' => true, 'lifetime' => self::CACHE_LIFETIME, 'language' => 'en-GB']
        );
    }
}
