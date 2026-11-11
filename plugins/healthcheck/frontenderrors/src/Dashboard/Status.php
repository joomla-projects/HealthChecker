<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Frontenderrors\Dashboard;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Status rules for the dashboard tile, page badges and trend.
 *
 * @since  __DEPLOY_VERSION__
 */
final class Status
{
    /**
     * Tile status.
     *
     * @param   integer  $openErrors      Open error count.
     * @param   integer  $worstPageErrors Open errors on the worst page.
     * @param   integer  $threshold       Per-page error threshold.
     * @param   boolean  $anyRegressed    Whether any error has regressed.
     *
     * @return  string  success|warning|error
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function tile(int $openErrors, int $worstPageErrors, int $threshold, bool $anyRegressed): string
    {
        if ($anyRegressed || $worstPageErrors >= $threshold) {
            return 'error';
        }

        return $openErrors > 0 ? 'warning' : 'success';
    }

    /**
     * Badge class for a page.
     *
     * @param   integer  $pageErrors  Open errors on the page.
     * @param   integer  $threshold   Per-page error threshold.
     *
     * @return  string  danger|warning
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function badge(int $pageErrors, int $threshold): string
    {
        return $pageErrors >= $threshold ? 'danger' : 'warning';
    }

    /**
     * Trend between two counts.
     *
     * @param   integer  $current   Current count.
     * @param   integer  $previous  Previous count.
     *
     * @return  array{direction: string, delta: int}
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function trend(int $current, int $previous): array
    {
        return [
            'direction' => $current > $previous ? 'up' : ($current < $previous ? 'down' : 'same'),
            'delta'     => abs($current - $previous),
        ];
    }
}
