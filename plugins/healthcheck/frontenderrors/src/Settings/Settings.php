<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Frontenderrors\Settings;

use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Validated settings of the Frontend Errors plugins.
 *
 * @since  __DEPLOY_VERSION__
 */
final class Settings
{
    /**
     * @param   string        $context         Health Check dashboard context
     * @param   string        $mode            Collection mode: ongoing or timed
     * @param   string|null   $collectUntil    Last collection day (Y-m-d) for timed mode
     * @param   boolean       $collectConsole  Whether console.error calls are collected
     * @param   integer       $sampleRate      Percentage of page views that collect (1-100)
     * @param   integer       $retentionDays   Days to keep data (1-365)
     * @param   integer       $thresholdPage   Page count that raises the status
     * @param   integer       $periodDays      Look-back period in days (1-90)
     * @param   string[]      $ignorePatterns  Message patterns to ignore
     * @param   integer       $scanLimit       Pages visited by a scan (5-100)
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(
        public readonly string $context,
        public readonly string $mode,
        public readonly ?string $collectUntil,
        public readonly bool $collectConsole,
        public readonly int $sampleRate,
        public readonly int $retentionDays,
        public readonly int $thresholdPage,
        public readonly int $periodDays,
        public readonly array $ignorePatterns,
        public readonly int $scanLimit
    ) {
    }

    /**
     * Build the settings from plugin params.
     *
     * @param   Registry  $params  The plugin params
     *
     * @return  self
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function fromParams(Registry $params): self
    {
        $mode = (string) $params->get('mode', 'ongoing') === 'timed' ? 'timed' : 'ongoing';

        $until = trim((string) $params->get('collect_until', ''));
        $date  = $until === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $until);

        if ($date === false || $date->format('Y-m-d') !== $until) {
            $until = null;
        }

        $patterns = [];

        foreach (preg_split('/\R/', (string) $params->get('ignore_patterns', '')) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $patterns[] = $line;
            }
        }

        $context = trim((string) $params->get('context', 'general'));

        return new self(
            $context === '' ? 'general' : $context,
            $mode,
            $until,
            (bool) $params->get('collect_console', 0),
            self::clamp($params->get('sample_rate', 100), 1, 100),
            self::clamp($params->get('retention_days', 30), 1, 365),
            self::clamp($params->get('threshold_page', 3), 1, \PHP_INT_MAX),
            self::clamp($params->get('period_days', 7), 1, 90),
            $patterns,
            self::clamp($params->get('scan_limit', 25), 5, 100)
        );
    }

    /**
     * Collection state for a given day.
     *
     * @param   string  $today  Today as Y-m-d
     *
     * @return  string  ongoing, until, ended or unset
     *
     * @since   __DEPLOY_VERSION__
     */
    public function collectionState(string $today): string
    {
        if ($this->mode === 'ongoing') {
            return 'ongoing';
        }

        if ($this->collectUntil === null) {
            return 'unset';
        }

        return $today <= $this->collectUntil ? 'until' : 'ended';
    }

    /**
     * Whether collection is active on a given day.
     *
     * @param   string  $today  Today as Y-m-d
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function isCollecting(string $today): bool
    {
        return \in_array($this->collectionState($today), ['ongoing', 'until'], true);
    }

    /**
     * Clamp a value to an integer range.
     *
     * @param   mixed    $value  The raw value
     * @param   integer  $min    Lower bound
     * @param   integer  $max    Upper bound
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
