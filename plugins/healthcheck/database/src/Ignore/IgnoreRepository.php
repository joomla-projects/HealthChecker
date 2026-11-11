<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Ignore;

use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Stores the ignored tables, columns and prefixes. This is the only place the plugin writes,
 * and it writes only to its own table.
 *
 * @since  __DEPLOY_VERSION__
 */
final class IgnoreRepository
{
    /**
     * The plugin's own table, its only write target.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const TABLE = '#__healthcheck_database_ignored';

    /**
     * Longest name in characters, the size of the `name` column.
     *
     * @var    integer
     * @since  __DEPLOY_VERSION__
     */
    public const MAX_NAME = 255;

    /**
     * Any characters but control characters, valid UTF-8. Names are always bound, never put in SQL text,
     * so a table with a hyphen, a literal `#__` or a 64 + 1 + 64 character table.column can be ignored too.
     *
     * @var    string
     * @since  __DEPLOY_VERSION__
     */
    private const NAME_PATTERN = '/^[^\x00-\x1F\x7F]{1,' . self::MAX_NAME . '}$/uD';

    /**
     * Constructor
     *
     * @param   DatabaseInterface  $db  The Joomla database
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * All ignore entries.
     *
     * @return  list<array{kind:string, name:string, created_by:int, created:string}>
     *
     * @since   __DEPLOY_VERSION__
     */
    public function all(): array
    {
        $db    = $this->db;
        $query = $db->createQuery()
            ->select($db->quoteName(['kind', 'name', 'created_by', 'created']))
            ->from($db->quoteName(self::TABLE))
            ->order($db->quoteName('id'));

        $out = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $out[] = [
                'kind'       => (string) $row['kind'],
                'name'       => (string) $row['name'],
                'created_by' => (int) $row['created_by'],
                'created'    => (string) $row['created'],
            ];
        }

        return $out;
    }

    /**
     * Add an entry. Adding an existing entry succeeds without changing it.
     *
     * Names are compared byte for byte. When the database collation holds another name equal to this one
     * (MySQL utf8mb4_unicode_ci: `cafe_log` and `café_log`), the UNIQUE key cannot take it: add() refuses
     * rather than claim an entry it did not store.
     *
     * @param   string  $kind    table, column or prefix
     * @param   string  $name    The name (table.column for a column); stored lowercased
     * @param   integer $userId  The user adding it
     * @param   string  $now     The creation date, UTC, Y-m-d H:i:s
     *
     * @return  boolean  False for invalid input, or when a collation-equal entry is in the way
     *
     * @throws  \RuntimeException  With a generic message when the entry cannot be stored; the detail is logged.
     *
     * @since   __DEPLOY_VERSION__
     */
    public function add(string $kind, string $name, int $userId, string $now): bool
    {
        $name = strtolower($name);

        if (!self::isValid($kind, $name)) {
            return false;
        }

        $candidates = $this->candidates($kind, $name);

        if (\in_array($name, $candidates, true)) {
            return true;
        }

        if ($candidates !== []) {
            return false;
        }

        $db      = $this->db;
        $columns = ['kind', 'name', 'created_by', 'created'];
        $query   = $db->createQuery()
            ->insert($db->quoteName(self::TABLE))
            ->columns($db->quoteName($columns))
            ->values(':kind, :name, :createdby, :created')
            ->bind(':kind', $kind)
            ->bind(':name', $name)
            ->bind(':createdby', $userId, ParameterType::INTEGER)
            ->bind(':created', $now);

        try {
            $db->setQuery($query)->execute();
        } catch (\RuntimeException $e) {
            try {
                $after = $this->candidates($kind, $name);
            } catch (\RuntimeException) {
                $after = [];
            }

            // A concurrent request won the unique key: the entry is there, which is what was asked.
            if (\in_array($name, $after, true)) {
                return true;
            }

            if ($after !== []) {
                return false;
            }

            // The driver message can name tables and SQL: log it, keep it out of the Ajax response.
            Log::add('Database Health Check: could not store the ignore entry: ' . $e->getMessage(), Log::WARNING, 'healthcheck');

            throw new \RuntimeException('Could not store the ignore entry', 500);
        }

        return true;
    }

    /**
     * Remove an entry.
     *
     * @param   string  $kind  table, column or prefix
     * @param   string  $name  The name; matched lowercased, byte for byte
     *
     * @return  boolean  True when a row was deleted
     *
     * @since   __DEPLOY_VERSION__
     */
    public function remove(string $kind, string $name): bool
    {
        $name = strtolower($name);
        $id   = array_search($name, $this->candidates($kind, $name), true);

        if ($id === false) {
            return false;
        }

        $db    = $this->db;
        $query = $db->createQuery()
            ->delete($db->quoteName(self::TABLE))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($query)->execute();

        return $db->getAffectedRows() > 0;
    }

    /**
     * Entries of the kind whose name the database collation takes as equal to $name. The caller compares
     * them byte for byte: a case- and accent-insensitive collation also returns `café_log` for `cafe_log`.
     *
     * @param   string  $kind  The kind
     * @param   string  $name  The lowercased name
     *
     * @return  array<int, string>  Stored name by id
     *
     * @since   __DEPLOY_VERSION__
     */
    private function candidates(string $kind, string $name): array
    {
        $db    = $this->db;
        $query = $db->createQuery()
            ->select($db->quoteName(['id', 'name']))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('kind') . ' = :kind')
            ->where($db->quoteName('name') . ' = :name')
            ->bind(':kind', $kind)
            ->bind(':name', $name);

        $out = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * Whether the kind and name are acceptable for an ignore entry. The Details popup uses it to offer
     * Ignore only where add() would accept it.
     *
     * A table may hold any characters but control characters, spaces at either end included (MySQL allows a
     * leading one, PostgreSQL both; a PAD SPACE collation match is told apart byte for byte by add() and
     * remove()). A column is `table.column`, split on the last dot, both sides non-empty. A prefix ends with
     * `_` and has no dot.
     *
     * @param   string  $kind  The kind
     * @param   string  $name  The name
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function isValid(string $kind, string $name): bool
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return false;
        }

        $lastDot = strrpos($name, '.');

        return match ($kind) {
            'table'  => true,
            'column' => $lastDot !== false && $lastDot > 0 && $lastDot < \strlen($name) - 1,
            'prefix' => $lastDot === false && str_ends_with($name, '_'),
            default  => false,
        };
    }
}
