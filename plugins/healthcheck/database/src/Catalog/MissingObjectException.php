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
 * A table or column a query needed no longer exists (dropped or renamed since it was listed).
 *
 * Only this exception means "gone"; any other failure (lost connection, permission, lock wait) is a plain
 * \RuntimeException, so a scan never reads it as an all-clear.
 *
 * @since  __DEPLOY_VERSION__
 */
final class MissingObjectException extends \RuntimeException
{
    /**
     * MySQL and MariaDB error numbers: 1146 no such table, 1054 unknown column.
     *
     * @var    integer[]
     * @since  __DEPLOY_VERSION__
     */
    private const ERRNOS = [1146, 1054];

    /**
     * SQLSTATEs: 42S02 / 42S22 (MySQL table / column), 42P01 / 42703 (PostgreSQL table / column).
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    private const SQLSTATES = ['42S02', '42S22', '42P01', '42703'];

    /**
     * Wrap a query failure: a MissingObjectException for a missing table or column, a plain
     * \RuntimeException otherwise. The original is kept as the previous exception either way.
     *
     * @param   \Throwable  $e        The query failure
     * @param   string      $message  Message for the new exception
     *
     * @return  \RuntimeException
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function wrap(\Throwable $e, string $message): \RuntimeException
    {
        $code = \is_int($e->getCode()) ? $e->getCode() : 0;

        return self::isMissingObject($e) ? new self($message, $code, $e) : new \RuntimeException($message, $code, $e);
    }

    /**
     * Whether a failure, or one it was chained from, says the table or column does not exist.
     *
     * Drivers report it differently: mysqli gives the error number as the code, PDO a message that starts
     * with the SQLSTATE ("42S02, 1146, Table ... doesn't exist") or holds "SQLSTATE[42S02]".
     *
     * @param   \Throwable  $e  The failure
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function isMissingObject(\Throwable $e): bool
    {
        $states = implode('|', self::SQLSTATES);

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $code = $current->getCode();

            if (\in_array($code, self::ERRNOS, true) || \in_array($code, self::SQLSTATES, true)) {
                return true;
            }

            if ($current instanceof \PDOException && \is_array($current->errorInfo)) {
                if (\in_array($current->errorInfo[0] ?? null, self::SQLSTATES, true) || \in_array($current->errorInfo[1] ?? null, self::ERRNOS, true)) {
                    return true;
                }
            }

            if ($current instanceof \mysqli_sql_exception && \in_array($current->getSqlState(), self::SQLSTATES, true)) {
                return true;
            }

            if (preg_match('/^(?:' . $states . ')\b|SQLSTATE\[(?:' . $states . ')\]/', $current->getMessage())) {
                return true;
            }
        }

        return false;
    }
}
