<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_admin
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Admin\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Model for the Health Check system information.
 *
 * @since  5.4
 */
class HealthcheckModel extends BaseDatabaseModel
{
    /**
     * Get health check data
     *
     * @return  array  Health data array
     *
     * @since   5.4
     */
    public function getHealthData(): array
    {
        // Mock data for now - will be replaced with real health checks
        return [
            'overall_score' => 85,
            'status_class' => 'success',
            'status_text' => Text::_('COM_ADMIN_HEALTH_CHECKER_STATUS_GOOD'),
            'joomla_version' => (new Version())->getShortVersion(),
            'last_scan' => Text::_('COM_ADMIN_HEALTH_CHECKER_SCAN_TIME_2_HOURS'),
            'target_version' => '5.2.0',
            'core_checks_passed' => 8,
            'core_checks_total' => 10,
            'core_checks' => [
                'security' => [
                    'status' => 'success',
                    'details' => [
                        'file_permissions' => true,
                        'config_security' => true,
                        'core_integrity' => true
                    ]
                ],
                'performance' => [
                    'status' => 'warning',
                    'details' => [
                        'database_optimization' => 78,
                        'cache_configuration' => 92
                    ]
                ]
            ]
        ];
    }

    /**
     * Get extension compatibility data
     *
     * @return  array  Extension data array
     *
     * @since   5.4
     */
    public function getExtensionData(): array
    {
        // Mock data - will be replaced with real extension scanning
        return [
            [
                'id' => 1,
                'name' => 'JCE Editor',
                'author' => 'JCE Team',
                'type' => 'component',
                'current_version' => '2.9.55',
                'compatible_version' => '3.1.2',
                'status' => 'needs_update',
                'risk_level' => 'medium'
            ],
            [
                'id' => 2,
                'name' => 'Akeeba Backup',
                'author' => 'Akeeba Ltd',
                'type' => 'component',
                'current_version' => '9.8.1',
                'compatible_version' => '9.8.1',
                'status' => 'compatible',
                'risk_level' => 'low'
            ],
            [
                'id' => 3,
                'name' => 'Custom Template',
                'author' => 'Custom Developer',
                'type' => 'template',
                'current_version' => '1.0.0',
                'compatible_version' => null,
                'status' => 'incompatible',
                'risk_level' => 'high'
            ]
        ];
    }

    /**
     * Get critical issues
     *
     * @return  array  Critical issues array
     *
     * @since   5.4
     */
    public function getCriticalIssues(): array
    {
        return [
            [
                'title' => Text::_('COM_ADMIN_HEALTH_CHECKER_ISSUE_LEGACY_EXTENSION'),
                'description' => Text::_('COM_ADMIN_HEALTH_CHECKER_ISSUE_JCE_UPDATE'),
                'severity' => 'warning'
            ],
            [
                'title' => Text::_('COM_ADMIN_HEALTH_CHECKER_ISSUE_INCOMPATIBLE'),
                'description' => Text::_('COM_ADMIN_HEALTH_CHECKER_ISSUE_CUSTOM_TEMPLATE'),
                'severity' => 'error'
            ],
            [
                'title' => Text::_('COM_ADMIN_HEALTH_CHECKER_ISSUE_DEPRECATED'),
                'description' => Text::_('COM_ADMIN_HEALTH_CHECKER_ISSUE_PHP_FUNCTIONS'),
                'severity' => 'info'
            ]
        ];
    }

    /**
     * Get upgrade recommendations
     *
     * @return  array  Recommendations array
     *
     * @since   5.4
     */
    public function getRecommendations(): array
    {
        return [
            'critical' => [
                Text::_('COM_ADMIN_HEALTH_CHECKER_RECOMMENDATION_UPDATE_JCE'),
                Text::_('COM_ADMIN_HEALTH_CHECKER_RECOMMENDATION_REVIEW_TEMPLATE')
            ],
            'medium' => [
                Text::_('COM_ADMIN_HEALTH_CHECKER_RECOMMENDATION_UPDATE_EXTENSIONS'),
                Text::_('COM_ADMIN_HEALTH_CHECKER_RECOMMENDATION_OPTIMIZE_DATABASE'),
                Text::_('COM_ADMIN_HEALTH_CHECKER_RECOMMENDATION_REVIEW_PHP_FUNCTIONS')
            ],
            'ready' => [
                Text::_('COM_ADMIN_HEALTH_CHECKER_RECOMMENDATION_CREATE_BACKUP'),
                Text::_('COM_ADMIN_HEALTH_CHECKER_RECOMMENDATION_SCHEDULE_UPGRADE'),
                Text::_('COM_ADMIN_HEALTH_CHECKER_RECOMMENDATION_PREPARE_ROLLBACK')
            ]
        ];
    }

    /**
     * Helper function to get status text
     *
     * @param   string  $status  Status key
     *
     * @return  string  Localized status text
     *
     * @since   5.4
     */
    public function getStatusText(string $status): string
    {
        return match ($status) {
            'compatible' => Text::_('COM_ADMIN_HEALTH_CHECKER_STATUS_COMPATIBLE'),
            'needs_update' => Text::_('COM_ADMIN_HEALTH_CHECKER_STATUS_NEEDS_UPDATE'),
            'incompatible' => Text::_('COM_ADMIN_HEALTH_CHECKER_STATUS_INCOMPATIBLE'),
            default => Text::_('COM_ADMIN_HEALTH_CHECKER_STATUS_UNKNOWN')
        };
    }

    /**
     * Helper function to get risk text
     *
     * @param   string  $risk  Risk level key
     *
     * @return  string  Localized risk text
     *
     * @since   5.4
     */
    public function getRiskText(string $risk): string
    {
        return match ($risk) {
            'low' => Text::_('COM_ADMIN_HEALTH_CHECKER_RISK_LOW'),
            'medium' => Text::_('COM_ADMIN_HEALTH_CHECKER_RISK_MEDIUM'),
            'high' => Text::_('COM_ADMIN_HEALTH_CHECKER_RISK_HIGH'),
            default => Text::_('COM_ADMIN_HEALTH_CHECKER_RISK_UNKNOWN')
        };
    }

    /**
     * Count extensions by status
     *
     * @param   array   $extensions  Extension data
     * @param   string  $status      Status to count
     *
     * @return  int  Count of extensions with status
     *
     * @since   5.4
     */
    public function countByStatus(array $extensions, string $status): int
    {
        return count(array_filter($extensions, fn($ext) => $ext['status'] === $status));
    }
}