<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Ownership;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Extracts the tables an SQL script creates (or renames to) from its text.
 *
 * Only names that start with the `#__` placeholder prefix are returned, lowercased.
 *
 * @since  __DEPLOY_VERSION__
 */
final class SqlTableNames
{
    /**
     * A table name token, optionally wrapped in backticks, double quotes or brackets.
     * The capture group holds the bare name.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const NAME = '[`"\[]?(#__[A-Za-z0-9_$]+)[`"\]]?';

    /**
     * Returns the table names defined by the given SQL, in first-seen order.
     *
     * @param   string  $sql  The SQL script
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function fromSql(string $sql): array
    {
        $sql = self::stripComments($sql);

        // Offset => name, so statements of different kinds keep their order in the script.
        $found = [];

        $pattern = '/\bCREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . self::NAME . '/i';

        if (preg_match_all($pattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as [$name, $offset]) {
                $found[$offset] = $name;
            }
        }

        $pattern = '/\bALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?' . self::NAME . '\s+RENAME\s+(?:TO\s+|AS\s+)' . self::NAME . '/i';

        if (preg_match_all($pattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as [$name, $offset]) {
                $found[$offset] = $name;
            }
        }

        if (preg_match_all('/\bRENAME\s+TABLE\s+([^;]+)/i', $sql, $statements, PREG_OFFSET_CAPTURE)) {
            foreach ($statements[1] as [$list, $listOffset]) {
                $pairs = '/' . self::NAME . '\s+TO\s+' . self::NAME . '/i';

                if (preg_match_all($pairs, $list, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[2] as [$name, $offset]) {
                        $found[$listOffset + $offset] = $name;
                    }
                }
            }
        }

        ksort($found);

        return array_values(array_unique(array_map('strtolower', $found)));
    }

    /**
     * Removes `-- ...`, `# ...` (at line start) and block comments.
     *
     * @param   string  $sql  The SQL script
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function stripComments(string $sql): string
    {
        $sql = preg_replace('~/\*.*?\*/~s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/--[^\r\n]*/', '', $sql) ?? $sql;

        // A `#` comment only counts at the start of a line; `#__` is the table prefix, never a comment.
        return preg_replace('/^[ \t]*#(?!__)[^\r\n]*/m', '', $sql) ?? $sql;
    }
}
