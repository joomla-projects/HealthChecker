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
 * The tables created by the Joomla core installation SQL.
 *
 * @since  __DEPLOY_VERSION__
 */
final class CoreTables
{
    /**
     * Lowercased `#__` table names, generated from installation/sql/mysql/{base,extensions,supports}.sql
     * with SqlTableNames::fromSql(). CoreTablesTest fails when the installation files drift from this list.
     *
     * @var    string[]
     * @since  __DEPLOY_VERSION__
     */
    public const NAMES = [
        '#__action_log_config',
        '#__action_logs',
        '#__action_logs_extensions',
        '#__action_logs_users',
        '#__assets',
        '#__associations',
        '#__banner_clients',
        '#__banner_tracks',
        '#__banners',
        '#__categories',
        '#__contact_details',
        '#__content',
        '#__content_frontpage',
        '#__content_rating',
        '#__content_types',
        '#__contentitem_tag_map',
        '#__extensions',
        '#__fields',
        '#__fields_categories',
        '#__fields_groups',
        '#__fields_values',
        '#__finder_filters',
        '#__finder_links',
        '#__finder_links_terms',
        '#__finder_logging',
        '#__finder_taxonomy',
        '#__finder_taxonomy_map',
        '#__finder_terms',
        '#__finder_terms_common',
        '#__finder_tokens',
        '#__finder_tokens_aggregate',
        '#__finder_types',
        '#__guidedtour_steps',
        '#__guidedtours',
        '#__healthcheck_database_ignored',
        '#__history',
        '#__languages',
        '#__mail_templates',
        '#__menu',
        '#__menu_types',
        '#__messages',
        '#__messages_cfg',
        '#__modules',
        '#__modules_menu',
        '#__newsfeeds',
        '#__overrider',
        '#__postinstall_messages',
        '#__privacy_consents',
        '#__privacy_requests',
        '#__redirect_links',
        '#__scheduler_logs',
        '#__scheduler_tasks',
        '#__schemaorg',
        '#__schemas',
        '#__session',
        '#__tags',
        '#__template_overrides',
        '#__template_styles',
        '#__tuf_metadata',
        '#__ucm_base',
        '#__ucm_content',
        '#__update_sites',
        '#__update_sites_extensions',
        '#__updates',
        '#__user_keys',
        '#__user_mfa',
        '#__user_notes',
        '#__user_profiles',
        '#__user_usergroup_map',
        '#__usergroups',
        '#__users',
        '#__viewlevels',
        '#__webauthn_credentials',
        '#__workflow_associations',
        '#__workflow_stages',
        '#__workflow_transitions',
        '#__workflows',
    ];

    /**
     * Whether the given table is a core table.
     *
     * @param   string  $prefixedName  Table name with the `#__` placeholder, any case
     *
     * @return  boolean
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function contains(string $prefixedName): bool
    {
        return \in_array(strtolower($prefixedName), self::NAMES, true);
    }
}
