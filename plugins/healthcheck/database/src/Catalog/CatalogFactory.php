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
 * Picks the catalog for the database driver in use.
 *
 * @since   __DEPLOY_VERSION__
 */
final class CatalogFactory
{
    /**
     * The catalog for the server type of the database.
     *
     * @param   DatabaseInterface  $db  The database.
     *
     * @return  Catalog
     *
     * @throws  \RuntimeException  For an unsupported server type.
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function for(DatabaseInterface $db): Catalog
    {
        return match ($db->getServerType()) {
            'mysql'      => new MysqlCatalog($db),
            'postgresql' => new PostgresqlCatalog($db),
            default      => throw new \RuntimeException('Unsupported database server type: ' . $db->getServerType()),
        };
    }
}
