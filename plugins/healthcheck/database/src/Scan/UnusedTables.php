<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Scan;

use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreList;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Finds the tables that no installed extension accounts for and groups them by prefix.
 *
 * @since  __DEPLOY_VERSION__
 */
final class UnusedTables
{
    /**
     * The tables nothing accounts for, grouped by prefix, the site prefix group first.
     *
     * @param   array<array{name:string, rows:?int, bytes:?int, updated:?string}>  $tables      Catalog tables
     * @param   string[]                                                           $accounted   Lowercased `#__` names
     * @param   string                                                             $sitePrefix  The site's table prefix
     * @param   IgnoreList                                                         $ignored     Ignore entries
     *
     * @return  array<array{prefix:string, own:bool, prefixIgnored:bool, tables:array}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function groups(array $tables, array $accounted, string $sitePrefix, IgnoreList $ignored): array
    {
        $known  = array_flip(array_map('strtolower', $accounted));
        $lower  = strtolower($sitePrefix);
        $groups = [];

        foreach ($tables as $table) {
            $name  = (string) $table['name'];
            $own   = $lower === '' || str_starts_with(strtolower($name), $lower);
            $match = $own ? strtolower('#__' . substr($name, \strlen($lower))) : '';

            if ($own && isset($known[$match])) {
                continue;
            }

            if ($own) {
                $prefix = $sitePrefix;
            } else {
                $pos    = strpos($name, '_');
                $prefix = $pos === false ? '' : substr($name, 0, $pos + 1);
            }

            $key = $own ? "\0own" : strtolower($prefix);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'prefix'        => $prefix,
                    'own'           => $own,
                    'prefixIgnored' => !$own && $ignored->isPrefixIgnored($prefix),
                    'tables'        => [],
                ];
            }

            $groups[$key]['tables'][] = [
                'name'    => $name,
                'rows'    => $table['rows'] ?? null,
                'bytes'   => $table['bytes'] ?? null,
                'updated' => $table['updated'] ?? null,
                'ignored' => $groups[$key]['prefixIgnored'] || $ignored->hasTableEntry($name),
            ];
        }

        uksort($groups, static fn ($a, $b) => ($a === "\0own" ? -1 : ($b === "\0own" ? 1 : strcmp($a, $b))));

        foreach ($groups as &$group) {
            usort($group['tables'], static fn ($a, $b) => strcmp($a['name'], $b['name']));
        }

        unset($group);

        return array_values($groups);
    }

    /**
     * Number of listed tables that are not ignored.
     *
     * @param   array  $groups  The result of groups()
     *
     * @return  integer  Tables listed that are not ignored
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function openCount(array $groups): int
    {
        return self::count($groups, false);
    }

    /**
     * Number of listed tables that are ignored.
     *
     * @param   array  $groups  The result of groups()
     *
     * @return  integer  Tables listed that are ignored
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function ignoredCount(array $groups): int
    {
        return self::count($groups, true);
    }

    /**
     * Count the tables whose ignored flag matches.
     *
     * @param   array    $groups   The groups
     * @param   boolean  $ignored  Which flag to count
     *
     * @return  integer
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function count(array $groups, bool $ignored): int
    {
        $total = 0;

        foreach ($groups as $group) {
            foreach ($group['tables'] as $table) {
                if ($table['ignored'] === $ignored) {
                    $total++;
                }
            }
        }

        return $total;
    }
}
