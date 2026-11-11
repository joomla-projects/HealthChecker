<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Securityheaders
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Securityheaders\Headers;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Finds the Joomla screen where a failing header can be fixed. Pure: no I/O.
 *
 * @since  __DEPLOY_VERSION__
 */
final class FixLink
{
    /**
     * Tab of the System - HTTP Headers plugin holding each header
     *
     * @since  __DEPLOY_VERSION__
     */
    private const PLUGIN_TABS = [
        'hsts'        => 'attrib-hsts',
        'csp'         => 'attrib-csp',
        'frame'       => 'attrib-basic',
        'referrer'    => 'attrib-basic',
        'coop'        => 'attrib-basic',
        'permissions' => 'attrib-basic',
    ];

    /**
     * The link for a scored item.
     *
     * @param   array     $item             A HeaderScore item (key, fix)
     * @param   ?integer  $httpHeadersId    Extension id of the System - HTTP Headers plugin, if found
     *
     * @return  array|null  ['url' => string, 'label' => language key], or null when there is no screen
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function for(array $item, ?int $httpHeadersId): ?array
    {
        if ($item['fix'] === null) {
            return null;
        }

        if ($item['fix'] === 'PLG_HEALTHCHECK_SECURITYHEADERS_FIX_HSTS_HTTP') {
            return ['url' => 'index.php?option=com_config#page-server', 'label' => 'PLG_HEALTHCHECK_SECURITYHEADERS_OPEN_CONFIG'];
        }

        if ($httpHeadersId === null || !isset(self::PLUGIN_TABS[$item['key']])) {
            return null;
        }

        return [
            'url'   => 'index.php?option=com_plugins&task=plugin.edit&extension_id=' . $httpHeadersId . '#' . self::PLUGIN_TABS[$item['key']],
            'label' => 'PLG_HEALTHCHECK_SECURITYHEADERS_OPEN_HTTPHEADERS',
        ];
    }
}
