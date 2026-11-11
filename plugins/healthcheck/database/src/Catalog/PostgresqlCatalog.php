<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Catalog;

use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Catalog for PostgreSQL. It has no zero dates.
 *
 * @since   __DEPLOY_VERSION__
 */
final class PostgresqlCatalog implements Catalog
{
    /**
     * Constructor
     *
     * @param   DatabaseInterface  $db  The database.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * {@inheritdoc}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function tables(): array
    {
        $db    = $this->db;
        $query = $db->createQuery()
            ->select(
                [
                    $db->quoteName('c.relname', 'name'),
                    $db->quoteName('c.reltuples', 'rows'),
                    'pg_total_relation_size(' . $db->quoteName('c.oid') . ') AS ' . $db->quoteName('bytes'),
                ]
            )
            ->from($db->quoteName('pg_class', 'c'))
            ->join('INNER', $db->quoteName('pg_namespace', 'n') . ' ON ' . $db->quoteName('n.oid') . ' = ' . $db->quoteName('c.relnamespace'))
            ->where($db->quoteName('n.nspname') . ' = current_schema()')
            ->where($db->quoteName('c.relkind') . ' IN (' . implode(',', $db->quote(['r', 'p'])) . ')')
            ->order($db->quoteName('c.relname'));

        $result = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $rows = (float) $row['rows'];

            $result[] = [
                'name'    => (string) $row['name'],
                'rows'    => $rows < 0 ? null : (int) $rows,
                'bytes'   => $row['bytes'] === null ? null : (int) $row['bytes'],
                'updated' => null,
            ];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function supportsZeroDates(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function dateColumns(string $tablePrefix): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function tableExists(string $table, array $columns = []): bool
    {
        $db    = $this->db;
        $query = $db->createQuery()
            ->select($db->quoteName('column_name'))
            ->from($db->quoteName('information_schema.columns'))
            ->where($db->quoteName('table_schema') . ' = current_schema()')
            ->where($db->quoteName('table_name') . ' = :table')
            ->bind(':table', $table);

        $present = array_map(static fn ($column): string => strtolower((string) $column), $db->setQuery($query)->loadColumn() ?: []);

        return $present !== [] && array_diff(array_map('strtolower', $columns), $present) === [];
    }

    /**
     * {@inheritdoc}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function zeroDateCounts(string $table, array $columns): array
    {
        return array_fill_keys($columns, 0);
    }
}
