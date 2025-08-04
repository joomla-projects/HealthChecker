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
     * Storage for discovered health check providers
     *
     * @var    array
     * @since  5.4
     */
    protected array $healthCheckProviders = [];


    /**
     * Discover modules with health check manifests
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function discoverHealthCheckModules(): void
    {
        // Discover modules
        $this->discoverModules();

        // Discover plugins
        $this->discoverPlugins();
    }

    /**
     * Discover modules with health check manifests
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function discoverModules(): void
    {
        $db = Factory::getDbo();

        // Get all published administrator modules
        $query = $db->getQuery(true)
            ->select(['module', 'params'])
            ->from('#__modules')
            ->where('client_id = 1') // Administrator
            ->where('published = 1');

        $db->setQuery($query);

        try {
            $modules = $db->loadObjectList();
        } catch (Exception $e) {
            return;
        }

        foreach ($modules as $module) {
            $this->checkModuleManifest($module->module);
        }
    }

    /**
     * Discover plugins with health check manifests
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function discoverPlugins(): void
    {
        $db = Factory::getDbo();

        // Get all enabled plugins
        $query = $db->getQuery(true)
            ->select(['type', 'element', 'folder'])
            ->from('#__extensions')
            ->where('type = ' . $db->quote('plugin'))
            ->where('enabled = 1');

        $db->setQuery($query);

        try {
            $plugins = $db->loadObjectList();
        } catch (Exception $e) {
            return;
        }

        foreach ($plugins as $plugin) {
            $this->checkPluginManifest($plugin->folder, $plugin->element);
        }
    }

    /**
     * Check if module has health check manifest declaration
     *
     * @param   string  $moduleName  Module name
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function checkModuleManifest(string $moduleName): void
    {
        $manifestPath = JPATH_ADMINISTRATOR . '/modules/' . $moduleName . '/' . $moduleName . '.xml';

        if (!file_exists($manifestPath)) {
            return;
        }

        try {
            $xml = simplexml_load_file($manifestPath);

            if ($xml === false || !isset($xml->healthcheck)) {
                return;
            }

            $healthcheck = $xml->healthcheck;

            // Check if enabled
            if ((string) $healthcheck['enabled'] !== '1') {
                return;
            }

            // Extract provider details
            $provider = $healthcheck->provider;
            $className = (string) $provider['class'];
            $methodName = (string) $provider['method'];

            if (empty($className) || empty($methodName)) {
                return;
            }

            // Call the module's health check method
            $this->callModuleHealthCheck($moduleName, $className, $methodName);
        } catch (Exception $e) {
            // Log error but continue with other modules
            Factory::getApplication()->enqueueMessage(
                'Error processing health check manifest for ' . $moduleName . ': ' . $e->getMessage(),
                'warning'
            );
        }
    }

    /**
     * Call module health check method dynamically
     *
     * @param   string  $moduleName  Module name
     * @param   string  $className   Helper class name
     * @param   string  $methodName  Method name
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function callModuleHealthCheck(string $moduleName, string $className, string $methodName): void
    {
        $helperFile = JPATH_ADMINISTRATOR . '/modules/' . $moduleName . '/helper.php';

        if (!file_exists($helperFile)) {
            return;
        }

        require_once $helperFile;

        if (!class_exists($className)) {
            return;
        }

        if (!method_exists($className, $methodName)) {
            return;
        }

        try {
            // Call the method and store results
            $healthData = call_user_func([$className, $methodName]);

            if (is_array($healthData)) {
                $this->healthCheckProviders[$moduleName] = $healthData;
            }
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage(
                'Error calling health check method for ' . $moduleName . ': ' . $e->getMessage(),
                'warning'
            );
        }
    }

    /**
     * Check if plugin has health check manifest declaration
     *
     * @param   string  $folder   Plugin folder
     * @param   string  $element  Plugin element name
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function checkPluginManifest(string $folder, string $element): void
    {
        $manifestPath = JPATH_PLUGINS . '/' . $folder . '/' . $element . '/' . $element . '.xml';

        if (!file_exists($manifestPath)) {
            return;
        }

        try {
            $xml = simplexml_load_file($manifestPath);

            if ($xml === false || !isset($xml->healthcheck)) {
                return;
            }

            $healthcheck = $xml->healthcheck;

            // Check if enabled
            if ((string) $healthcheck['enabled'] !== '1') {
                return;
            }

            // Extract provider details
            $provider = $healthcheck->provider;
            $className = (string) $provider['class'];
            $methodName = (string) $provider['method'];

            if (empty($className) || empty($methodName)) {
                return;
            }

            // Call the plugin's health check method
            $this->callPluginHealthCheck($folder, $element, $className, $methodName);
        } catch (Exception $e) {
            // Log error but continue with other plugins
            Factory::getApplication()->enqueueMessage(
                'Error processing health check manifest for plugin ' . $folder . '/' . $element . ': ' . $e->getMessage(),
                'warning'
            );
        }
    }

    /**
     * Call plugin health check method dynamically
     *
     * @param   string  $folder     Plugin folder
     * @param   string  $element    Plugin element name
     * @param   string  $className  Helper class name
     * @param   string  $methodName Method name
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function callPluginHealthCheck(string $folder, string $element, string $className, string $methodName): void
    {
        $pluginFile = JPATH_PLUGINS . '/' . $folder . '/' . $element . '/' . $element . '.php';

        if (!file_exists($pluginFile)) {
            return;
        }

        require_once $pluginFile;
        
        // Also try to load helper.php if the class isn't found
        $helperFile = JPATH_PLUGINS . '/' . $folder . '/' . $element . '/helper.php';
        if (file_exists($helperFile)) {
            require_once $helperFile;
        }

        if (!class_exists($className)) {
            return;
        }

        if (!method_exists($className, $methodName)) {
            return;
        }

        try {
            // Call the method and store results
            $healthData = call_user_func([$className, $methodName]);

            if (is_array($healthData)) {
                $this->healthCheckProviders[$folder . '_' . $element] = $healthData;
            }
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage(
                'Error calling health check method for plugin ' . $folder . '/' . $element . ': ' . $e->getMessage(),
                'warning'
            );
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
        // Discover modules with health check capabilities
        $this->discoverHealthCheckModules();

        // Get all checks from discovered modules only
        $allChecks = [];
        $totalChecks = 0;
        $passedChecks = 0;

        // Add discovered health checks from all providers
        foreach ($this->healthCheckProviders as $providerName => $providerChecks) {
            foreach ($providerChecks as $check) {
                $totalChecks++;
                if ($check['status'] === 'success') {
                    $passedChecks++;
                }

                // Use check category or default to 'modules'
                $category = $check['category'] ?? 'modules';
                $allChecks[$category][] = $check;
            }
        }

        // If no providers discovered, show informational message
        if (empty($this->healthCheckProviders)) {
            $allChecks['system'][] = [
                'id' => 'no_modules',
                'title' => 'No Health Check Modules',
                'status' => 'info',
                'count' => 0,
                'message' => 'No plugins or modules with health check capabilities found. Install plugins/modules with <healthcheck> manifest elements to see health data.',
                'details' => [],
                'actions' => []
            ];
            $totalChecks = 1;
            $passedChecks = 1; // Info status counts as passed
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
        $extensionData = [];

        // Get extension-related checks from discovered providers
        foreach ($this->healthCheckProviders as $providerName => $providerChecks) {
            foreach ($providerChecks as $check) {
                if (isset($check['details']['items'])) {
                    foreach ($check['details']['items'] as $item) {
                        $extensionData[] = [
                            'id' => $item['id'] ?? uniqid(),
                            'name' => $item['title'] ?? 'Unknown',
                            'type' => $item['metadata']['type'] ?? 'module',
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
        $criticalIssues = [];

        // Get critical issues from discovered providers only
        foreach ($this->healthCheckProviders as $providerName => $providerChecks) {
            foreach ($providerChecks as $check) {
                if ($check['status'] === 'error') {
                    $criticalIssues[] = [
                        'title' => $check['title'],
                        'description' => $check['message'],
                        'severity' => 'error',
                        'category' => $check['category'] ?? 'modules'
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
        $recommendations = [
            'critical' => [],
            'medium' => [],
            'ready' => []
        ];

        // Generate recommendations from discovered providers only
        foreach ($this->healthCheckProviders as $providerName => $providerChecks) {
            foreach ($providerChecks as $check) {
                if ($check['status'] === 'error') {
                    $recommendations['critical'][] = 'Fix: ' . $check['title'];
                } elseif ($check['status'] === 'warning') {
                    $recommendations['medium'][] = 'Review: ' . $check['title'];
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
     * @return  string  Localised status text
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
     * @return  string  Localised risk text
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
     * Get all discovered health check providers
     *
     * @return  array  Array of health check provider data
     *
     * @since   5.4
     */
    public function getHealthCheckProviders(): array
    {
        $this->discoverHealthCheckModules();
        return $this->healthCheckProviders;
    }
}
