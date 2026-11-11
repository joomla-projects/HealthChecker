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
 * Catalog for MySQL and MariaDB (mysqli and pdomysql drivers).
 *
 * @since   __DEPLOY_VERSION__
 */
final class MysqlCatalog implements Catalog
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
                    $db->quoteName('TABLE_NAME', 'name'),
                    $db->quoteName('TABLE_ROWS', 'rows'),
                    $db->quoteName('DATA_LENGTH', 'data_length'),
                    $db->quoteName('INDEX_LENGTH', 'index_length'),
                    // UPDATE_TIME is a DATETIME in the session time zone, not UTC. UNIX_TIMESTAMP() reads it
                    // back in that same zone, so the epoch is right on MySQL and MariaDB without time zone tables.
                    'UNIX_TIMESTAMP(' . $db->quoteName('UPDATE_TIME') . ') AS ' . $db->quoteName('updated'),
                ]
            )
            ->from($db->quoteName('information_schema.TABLES'))
            ->where($db->quoteName('TABLE_SCHEMA') . ' = DATABASE()')
            ->where($db->quoteName('TABLE_TYPE') . ' = ' . $db->quote('BASE TABLE'))
            ->order($db->quoteName('TABLE_NAME'));

        $result = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $bytes = ($row['data_length'] === null && $row['index_length'] === null)
                ? null
                : (int) $row['data_length'] + (int) $row['index_length'];

            $result[] = [
                'name'    => (string) $row['name'],
                'rows'    => $row['rows'] === null ? null : (int) $row['rows'],
                'bytes'   => $bytes,
                'updated' => ($row['updated'] === null || (int) $row['updated'] <= 0) ? null : gmdate('Y-m-d H:i:s', (int) $row['updated']),
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
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function dateColumns(string $tablePrefix): array
    {
        $db      = $this->db;
        $pattern = addcslashes($tablePrefix, '\\_%') . '%';
        $query   = $db->createQuery()
            ->select(
                [
                    $db->quoteName('c.TABLE_NAME', 'tbl'),
                    $db->quoteName('c.COLUMN_NAME', 'col'),
                    $db->quoteName('c.DATA_TYPE', 'typ'),
                ]
            )
            ->from($db->quoteName('information_schema.COLUMNS', 'c'))
            // Base tables only, like tables(): a view over a table would count its zero dates twice.
            ->join(
                'INNER',
                $db->quoteName('information_schema.TABLES', 't'),
                $db->quoteName('t.TABLE_SCHEMA') . ' = ' . $db->quoteName('c.TABLE_SCHEMA')
                    . ' AND ' . $db->quoteName('t.TABLE_NAME') . ' = ' . $db->quoteName('c.TABLE_NAME')
            )
            ->where($db->quoteName('c.TABLE_SCHEMA') . ' = DATABASE()')
            ->where($db->quoteName('t.TABLE_TYPE') . ' = ' . $db->quote('BASE TABLE'))
            ->where($db->quoteName('c.DATA_TYPE') . ' IN (' . implode(',', $db->quote(['date', 'datetime', 'timestamp'])) . ')')
            ->where($db->quoteName('c.TABLE_NAME') . ' LIKE :prefix')
            ->bind(':prefix', $pattern)
            ->order([$db->quoteName('c.TABLE_NAME'), $db->quoteName('c.ORDINAL_POSITION')]);

        $result = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $result[] = ['table' => (string) $row['tbl'], 'column' => (string) $row['col'], 'type' => strtolower((string) $row['typ'])];
        }

        return $result;
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
            ->select($db->quoteName('COLUMN_NAME'))
            ->from($db->quoteName('information_schema.COLUMNS'))
            ->where($db->quoteName('TABLE_SCHEMA') . ' = DATABASE()')
            ->where($db->quoteName('TABLE_NAME') . ' = :table')
            ->bind(':table', $table);

        return self::hasColumns($db->setQuery($query)->loadColumn() ?: [], $columns);
    }

    /**
     * {@inheritdoc}
     *
     * @since   __DEPLOY_VERSION__
     */
    public function zeroDateCounts(string $table, array $columns): array
    {
        if ($columns === []) {
            return [];
        }

        $db     = $this->db;
        $select = [];

        foreach (array_values($columns) as $i => $column) {
            $select[] = 'SUM(CASE WHEN CAST(' . self::identifier($column) . ' AS CHAR(10)) = ' . $db->quote('0000-00-00')
                . ' THEN 1 ELSE 0 END) AS ' . $db->quoteName('c' . $i);
        }

        $query = $db->createQuery()
            ->select($select)
            ->from(self::identifier($table));

        try {
            $row = $db->setQuery($query)->loadRow() ?: [];
        } catch (\RuntimeException $e) {
            throw MissingObjectException::wrap($e, 'Could not count the zero dates of ' . $table . ': ' . $e->getMessage());
        }

        $counts = [];

        foreach (array_values($columns) as $i => $column) {
            $counts[$column] = (int) ($row[$i] ?? 0);
        }

        return $counts;
    }

    /**
     * An identifier quoted as one part. quoteName() splits on dots, which turns a column `a.b` into `a`.`b`.
     *
     * @param   string  $name  The identifier
     *
     * @return  string
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Whether a table with these columns exists (it has at least one) and holds every wanted column.
     *
     * @param   string[]  $present  Columns of the table, none when it does not exist
     * @param   string[]  $wanted   Columns that must be there
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    private static function hasColumns(array $present, array $wanted): bool
    {
        if ($present === []) {
            return false;
        }

        $present = array_map(static fn ($column): string => strtolower((string) $column), $present);

        return array_diff(array_map('strtolower', $wanted), $present) === [];
    }
}
