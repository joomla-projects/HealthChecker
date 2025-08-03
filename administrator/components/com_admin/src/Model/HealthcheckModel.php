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
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\Component\Admin\Administrator\Interface\HealthCheckProviderInterface;
use Joomla\Component\Admin\Administrator\HealthCheck\SeoHealthCheck;
use Joomla\Component\Admin\Administrator\HealthCheck\SystemHealthCheck;
use Joomla\Component\Admin\Administrator\HealthCheck\MetadescModuleAdapter;

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
     * Array of registered health check providers
     *
     * @var    HealthCheckProviderInterface[]
     * @since  5.4
     */
    protected array $healthCheckProviders = [];

    /**
     * Initialize and discover health check providers
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function initializeProviders(): void
    {
        // Only initialize once
        static $initialized = false;
        if ($initialized || !empty($this->healthCheckProviders)) {
            return;
        }
        
        // Register built-in providers
        $this->healthCheckProviders[] = new SeoHealthCheck();
        $this->healthCheckProviders[] = new SystemHealthCheck();
        
        // Discover and register health check modules
        $this->discoverHealthCheckModules();
        
        // TODO: Add plugin discovery system to find external plugins
        
        $initialized = true;
    }

    /**
     * Discover health check modules and create adapters
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function discoverHealthCheckModules(): void
    {
        $db = Factory::getDbo();
        
        // Known health check modules and their adapters
        $healthCheckModules = [
            'mod_metadesc_checker' => MetadescModuleAdapter::class,
            // Add more module adapters here as they're created
        ];
        
        foreach ($healthCheckModules as $moduleName => $adapterClass) {
            // Check if module is installed and published
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__modules')
                ->where('module = ' . $db->quote($moduleName))
                ->where('client_id = 1') // Administrator
                ->where('published = 1');
                
            $db->setQuery($query);
            
            try {
                $count = (int) $db->loadResult();
                if ($count > 0) {
                    // Module is available, create adapter
                    $adapter = new $adapterClass();
                    if ($adapter instanceof HealthCheckProviderInterface) {
                        $this->healthCheckProviders[] = $adapter;
                        Factory::getApplication()->enqueueMessage(
                            "Successfully loaded health check module: {$moduleName}",
                            'info'
                        );
                    }
                } else {
                    Factory::getApplication()->enqueueMessage(
                        "Module {$moduleName} not found or not published (count: {$count})",
                        'info'
                    );
                }
            } catch (Exception $e) {
                // Log error but continue with other modules
                Factory::getApplication()->enqueueMessage(
                    'Error loading health check module ' . $moduleName . ': ' . $e->getMessage(),
                    'warning'
                );
            }
        }
    }
    /**
     * Get health check data
     *
     * @return  array  Health data array
     *
     * @since   5.4
     */
    public function getHealthData(): array
    {
        $this->initializeProviders();
        
        // Get all checks from providers
        $allChecks = [];
        $totalChecks = 0;
        $passedChecks = 0;
        
        foreach ($this->healthCheckProviders as $provider) {
            if (!$provider->isEnabled()) {
                continue;
            }
            
            $checks = $provider->getChecks();
            foreach ($checks as $check) {
                $totalChecks++;
                if ($check['status'] === 'success') {
                    $passedChecks++;
                }
                
                $allChecks[$provider->getCategory()][] = $check;
            }
        }
        
        // Calculate overall score
        $score = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100) : 100;
        
        // Determine status based on score
        $statusClass = 'success';
        $statusText = Text::_('COM_ADMIN_HEALTH_CHECKER_STATUS_GOOD');
        
        if ($score < 60) {
            $statusClass = 'danger';
            $statusText = Text::_('COM_ADMIN_HEALTH_CHECKER_STATUS_POOR');
        } elseif ($score < 80) {
            $statusClass = 'warning';
            $statusText = Text::_('COM_ADMIN_HEALTH_CHECKER_STATUS_FAIR');
        }
        
        return [
            'overall_score' => $score,
            'status_class' => $statusClass,
            'status_text' => $statusText,
            'joomla_version' => (new Version())->getShortVersion(),
            'last_scan' => Text::_('COM_ADMIN_HEALTH_CHECKER_SCAN_TIME_NOW'),
            'target_version' => '5.2.0',
            'core_checks_passed' => $passedChecks,
            'core_checks_total' => $totalChecks,
            'core_checks' => $allChecks
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
        $this->initializeProviders();
        
        $extensionData = [];
        
        // Get extension-related checks from providers
        foreach ($this->healthCheckProviders as $provider) {
            if (!$provider->isEnabled() || $provider->getCategory() !== 'extensions') {
                continue;
            }
            
            $checks = $provider->getChecks();
            foreach ($checks as $check) {
                if (isset($check['details']['items'])) {
                    foreach ($check['details']['items'] as $item) {
                        $extensionData[] = [
                            'id' => $item['id'] ?? uniqid(),
                            'name' => $item['title'] ?? 'Unknown',
                            'type' => $item['metadata']['type'] ?? 'unknown',
                            'status' => $check['status'],
                            'message' => $item['description'] ?? $check['message'],
                            'current_version' => $item['metadata']['current_version'] ?? 'Unknown',
                            'compatible_version' => $item['metadata']['compatible_version'] ?? 'Unknown',
                            'author' => $item['metadata']['author'] ?? 'Unknown',
                            'risk_level' => $item['metadata']['risk_level'] ?? ($check['status'] === 'error' ? 'high' : ($check['status'] === 'warning' ? 'medium' : 'low'))
                        ];
                    }
                }
            }
        }
        
        // Fallback mock data if no extension providers are available
        if (empty($extensionData)) {
            return [
                [
                    'id' => 1,
                    'name' => 'No Extension Health Check Plugins',
                    'type' => 'system',
                    'status' => 'info',
                    'message' => 'Install extension health check plugins to see extension compatibility data',
                    'current_version' => 'N/A',
                    'compatible_version' => 'N/A',
                    'author' => 'System',
                    'risk_level' => 'low'
                ]
            ];
        }
        
        return $extensionData;
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
        $this->initializeProviders();
        
        $criticalIssues = [];
        
        // Get all issues from providers
        foreach ($this->healthCheckProviders as $provider) {
            if (!$provider->isEnabled()) {
                continue;
            }
            
            $checks = $provider->getChecks();
            foreach ($checks as $check) {
                // Only include ERROR status as critical issues, not warnings
                if ($check['status'] === 'error') {
                    $criticalIssues[] = [
                        'title' => $check['title'],
                        'description' => $check['message'],
                        'severity' => 'error',
                        'category' => $provider->getCategory()
                    ];
                }
            }
        }
        
        // If no critical issues found, return empty array
        return $criticalIssues;
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
        $this->initializeProviders();
        
        $recommendations = [
            'critical' => [],
            'medium' => [],
            'ready' => []
        ];
        
        // Generate recommendations based on check results
        foreach ($this->healthCheckProviders as $provider) {
            if (!$provider->isEnabled()) {
                continue;
            }
            
            $checks = $provider->getChecks();
            foreach ($checks as $check) {
                if ($check['status'] === 'error') {
                    $recommendations['critical'][] = 'Fix: ' . $check['title'];
                } elseif ($check['status'] === 'warning') {
                    $recommendations['medium'][] = 'Review: ' . $check['title'];
                } elseif ($check['status'] === 'success' && $check['count'] === 0) {
                    // Good checks don't need recommendations, but we could add optimization tips
                }
            }
        }
        
        // Add general ready-to-proceed items if no critical issues
        if (empty($recommendations['critical'])) {
            $recommendations['ready'] = [
                Text::_('COM_ADMIN_HEALTH_CHECKER_RECOMMENDATION_CREATE_BACKUP'),
                Text::_('COM_ADMIN_HEALTH_CHECKER_RECOMMENDATION_SCHEDULE_UPGRADE'),
                Text::_('COM_ADMIN_HEALTH_CHECKER_RECOMMENDATION_PREPARE_ROLLBACK')
            ];
        }
        
        return $recommendations;
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
    public function getRiskText(?string $risk): string
    {
        if ($risk === null) {
            return Text::_('COM_ADMIN_HEALTH_CHECKER_RISK_UNKNOWN');
        }
        
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

    /**
     * Get all registered health check providers
     *
     * @return  HealthCheckProviderInterface[]  Array of providers
     *
     * @since   5.4
     */
    public function getHealthCheckProviders(): array
    {
        $this->initializeProviders();
        return $this->healthCheckProviders;
    }

    /**
     * Get health check providers by category
     *
     * @param   string  $category  Category to filter by
     *
     * @return  HealthCheckProviderInterface[]  Array of providers
     *
     * @since   5.4
     */
    public function getProvidersByCategory(string $category): array
    {
        $this->initializeProviders();
        
        return array_filter($this->healthCheckProviders, function($provider) use ($category) {
            return $provider->getCategory() === $category && $provider->isEnabled();
        });
    }
}