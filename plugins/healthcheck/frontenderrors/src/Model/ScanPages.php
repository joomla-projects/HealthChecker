<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Frontenderrors\Model;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Lists the site menu items that Scan now visits.
 *
 * @since  __DEPLOY_VERSION__
 */
final class ScanPages
{
    /**
     * Constructor
     *
     * @param   DatabaseInterface  $db  The database
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Published site menu items that render a page and that the given view levels can see, in menu
     * order. The home item is left out: the caller adds the site root first.
     *
     * @param   integer    $limit       Maximum number of items
     * @param   integer[]  $viewLevels  View levels allowed to see the item (a guest's levels)
     * @param   string     $now         Current UTC time, `Y-m-d H:i:s`, for the publish window
     *
     * @return  integer[]  Menu item ids
     *
     * @since   __DEPLOY_VERSION__
     */
    public function itemIds(int $limit, array $viewLevels, string $now): array
    {
        $viewLevels = array_values(array_unique(array_map('intval', $viewLevels)));

        if ($limit < 1 || $viewLevels === []) {
            return [];
        }

        $db        = $this->db;
        $client    = 0;
        $published = 1;
        $home      = 0;
        $type      = 'component';
        $query     = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('client_id') . ' = :client')
            ->where($db->quoteName('published') . ' = :published')
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('home') . ' = :home')
            ->where('(' . $db->quoteName('publish_up') . ' IS NULL OR ' . $db->quoteName('publish_up') . ' <= :nowUp)')
            ->where('(' . $db->quoteName('publish_down') . ' IS NULL OR ' . $db->quoteName('publish_down') . ' >= :nowDown)')
            ->whereIn($db->quoteName('access'), $viewLevels)
            ->order([$db->quoteName('lft') . ' ASC', $db->quoteName('id') . ' ASC'])
            ->bind(':client', $client, ParameterType::INTEGER)
            ->bind(':published', $published, ParameterType::INTEGER)
            ->bind(':type', $type)
            ->bind(':home', $home, ParameterType::INTEGER)
            ->bind(':nowUp', $now)
            ->bind(':nowDown', $now)
            ->setLimit($limit);

        return array_map('intval', $db->setQuery($query)->loadColumn());
    }
}
