<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Ignore;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The set of ignored tables, columns and prefixes. Matching is case-insensitive.
 *
 * @since  __DEPLOY_VERSION__
 */
final class IgnoreList
{
    /**
     * @var    array<string, true>  Lowercased table names with a table entry
     * @since  __DEPLOY_VERSION__
     */
    private array $tables = [];

    /**
     * @var    array<string, true>  Lowercased table.column names with a column entry
     * @since  __DEPLOY_VERSION__
     */
    private array $columns = [];

    /**
     * @var    array<string, true>  Lowercased prefixes with an Ignore all entry
     * @since  __DEPLOY_VERSION__
     */
    private array $prefixes = [];

    /**
     * Constructor. Entries with an unknown kind or an empty name are left out.
     *
     * @param   array<array{kind:string, name:string}>  $entries  Ignore entries
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            $name = strtolower((string) ($entry['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            switch ($entry['kind'] ?? '') {
                case 'table':
                    $this->tables[$name] = true;
                    break;
                case 'column':
                    $this->columns[$name] = true;
                    break;
                case 'prefix':
                    $this->prefixes[$name] = true;
                    break;
            }
        }
    }

    /**
     * Whether the table has an Ignore entry of its own.
     *
     * @param   string  $table  Table name
     *
     * @return  boolean  True only for an explicit table entry (prefix entries are not considered)
     *
     * @since   __DEPLOY_VERSION__
     */
    public function hasTableEntry(string $table): bool
    {
        return isset($this->tables[strtolower($table)]);
    }

    /**
     * Whether the prefix has an Ignore all entry.
     *
     * @param   string  $prefix  Table prefix
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function isPrefixIgnored(string $prefix): bool
    {
        return isset($this->prefixes[strtolower($prefix)]);
    }

    /**
     * Whether the column has an Ignore entry (stored as table.column).
     *
     * @param   string  $table   Table name
     * @param   string  $column  Column name
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function isColumnIgnored(string $table, string $column): bool
    {
        return isset($this->columns[strtolower($table . '.' . $column)]);
    }
}
