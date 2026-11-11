<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Catalog;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Read-only access to the database metadata the Database health check needs.
 *
 * @since   __DEPLOY_VERSION__
 */
interface Catalog
{
    /**
     * Base tables (no views) of the current database or schema.
     *
     * @return  list<array{name:string, rows:?int, bytes:?int, updated:?string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function tables(): array;

    /**
     * Whether the engine can store zero dates (0000-00-00).
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function supportsZeroDates(): bool;

    /**
     * Date, datetime and timestamp columns of the tables starting with the prefix.
     *
     * @param   string  $tablePrefix  The table name prefix.
     *
     * @return  list<array{table:string, column:string, type:string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function dateColumns(string $tablePrefix): array;

    /**
     * Whether the table exists in the current database or schema with all the given columns. Used after a
     * count failed as if the table were gone, to tell a real drop from a name the query could not reach.
     *
     * @param   string    $table    The real table name (prefix applied), compared as the server compares it.
     * @param   string[]  $columns  Column names, compared case-insensitively.
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public function tableExists(string $table, array $columns = []): bool;

    /**
     * Number of rows holding a zero date in each of the columns, counted in one scan of the table.
     *
     * @param   string    $table    The real table name (prefix applied).
     * @param   string[]  $columns  The column names.
     *
     * @return  array<string, int>  Count per column name, in the order given
     *
     * @throws  MissingObjectException  If the table or a column no longer exists.
     * @throws  \RuntimeException       On any other failure (the original is the previous exception).
     *
     * @since   __DEPLOY_VERSION__
     */
    public function zeroDateCounts(string $table, array $columns): array;
}
