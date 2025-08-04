<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_admin
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Admin\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Healthcheck Configuration Controller
 *
 * @since  5.4
 */
class HealthcheckconfigController extends FormController
{
    /**
     * Save the configuration
     *
     * @param   string  $key     The name of the primary key of the URL variable.
     * @param   string  $urlVar  The name of the URL variable if different from the primary key.
     *
     * @return  void
     *
     * @since   5.4
     */
    public function save($key = null, $urlVar = null)
    {
        $this->saveConfiguration();
        
        // Save & Close - go back to main healthcheck view
        $this->setRedirect(Route::_('index.php?option=com_admin&view=healthcheck', false));
    }

    /**
     * Apply the configuration (save and stay)
     *
     * @param   string  $key     The name of the primary key of the URL variable.
     * @param   string  $urlVar  The name of the URL variable if different from the primary key.
     *
     * @return  void
     *
     * @since   5.4
     */
    public function apply($key = null, $urlVar = null)
    {
        $this->saveConfiguration();
        
        // Stay on the configuration page
        $this->setRedirect(Route::_('index.php?option=com_admin&view=healthcheck', false));
    }

    /**
     * Save configuration data (shared method)
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function saveConfiguration()
    {
        // Check for request forgeries
        $this->checkToken();

        $app = Factory::getApplication();
        $input = $app->getInput();

        try {
            $providers = $input->get('providers', [], 'array');
            $global = $input->get('global', [], 'array');

            // Process provider settings
            $providerConfig = [];
            foreach ($providers as $providerKey => $settings) {
                $providerConfig[$providerKey] = [
                    'enabled' => isset($settings['enabled']) && $settings['enabled'] == '1',
                    'priority' => (int) ($settings['priority'] ?? 10),
                    'settings' => $settings['settings'] ?? []
                ];
            }

            // Save to component parameters
            $db = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select('params')
                ->from('#__extensions')
                ->where('element = ' . $db->quote('com_admin'))
                ->where('type = ' . $db->quote('component'));

            $db->setQuery($query);
            $currentParams = $db->loadResult();

            $params = $currentParams ? json_decode($currentParams, true) : [];
            $params['healthcheck_plugins'] = $providerConfig;
            $params['healthcheck_global'] = $global;

            // Update database
            $query = $db->getQuery(true)
                ->update('#__extensions')
                ->set('params = ' . $db->quote(json_encode($params)))
                ->where('element = ' . $db->quote('com_admin'))
                ->where('type = ' . $db->quote('component'));

            $db->setQuery($query);
            $db->execute();

            $app->enqueueMessage(Text::_('JLIB_APPLICATION_SAVE_SUCCESS'), 'success');
        } catch (\Exception $e) {
            $app->enqueueMessage(Text::_('JERROR_SAVE_FAILED') . ': ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Test a specific provider via AJAX
     *
     * @return  void
     *
     * @since   5.4
     */
    public function testProvider()
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        // Check for request forgeries
        $this->checkToken();

        try {
            $providerKey = $input->getCmd('provider');

            if (empty($providerKey)) {
                throw new \Exception('No provider specified');
            }

            /** @var \Joomla\Component\Admin\Administrator\Model\HealthcheckModel $model */
            $model = $this->getModel('Healthcheck');
            $providers = $model->getHealthCheckProviders();

            $targetProvider = null;
            foreach ($providers as $provider) {
                if ($this->getProviderKey($provider) === $providerKey) {
                    $targetProvider = $provider;
                    break;
                }
            }

            if (!$targetProvider) {
                throw new \Exception('Provider not found: ' . $providerKey);
            }

            // Execute the provider's checks
            $checks = $targetProvider->getChecks();

            $response = [
                'success' => true,
                'provider' => $targetProvider->getName(),
                'category' => $targetProvider->getCategory(),
                'checksCount' => count($checks),
                'checks' => $checks
            ];

            echo new JsonResponse($response);
        } catch (\Exception $e) {
            echo new JsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }

        $app->close();
    }

    /**
     * Reorder providers via AJAX
     *
     * @return  void
     *
     * @since   5.4
     */
    public function reorderProviders()
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        // Check for request forgeries
        $this->checkToken();

        try {
            $providerOrder = $input->get('providerOrder', [], 'array');

            // Get current configuration
            $db = Factory::getDbo();
            $query = $db->getQuery(true)
                ->select('params')
                ->from('#__extensions')
                ->where('element = ' . $db->quote('com_admin'))
                ->where('type = ' . $db->quote('component'));

            $db->setQuery($query);
            $currentParams = $db->loadResult();

            $params = $currentParams ? json_decode($currentParams, true) : [];
            $providerConfig = $params['healthcheck_plugins'] ?? [];

            // Update priorities based on order
            foreach ($providerOrder as $index => $providerKey) {
                if (isset($providerConfig[$providerKey])) {
                    $providerConfig[$providerKey]['priority'] = ($index + 1) * 10;
                }
            }

            $params['healthcheck_plugins'] = $providerConfig;

            // Save back to database
            $query = $db->getQuery(true)
                ->update('#__extensions')
                ->set('params = ' . $db->quote(json_encode($params)))
                ->where('element = ' . $db->quote('com_admin'))
                ->where('type = ' . $db->quote('component'));

            $db->setQuery($query);
            $db->execute();

            echo new JsonResponse(['success' => true, 'message' => 'Provider order updated']);
        } catch (\Exception $e) {
            echo new JsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }

        $app->close();
    }

    /**
     * Get a unique key for a provider
     *
     * @param   object  $provider  The provider instance
     *
     * @return  string  Unique provider key
     *
     * @since   5.4
     */
    protected function getProviderKey($provider): string
    {
        // Provider is now a provider name string, not an object
        return strtolower(str_replace(['\\', ' '], ['_', '_'], $provider));
    }

    /**
     * Cancel operation
     *
     * @param   string  $key  The name of the primary key of the URL variable.
     *
     * @return  void
     *
     * @since   5.4
     */
    public function cancel($key = null)
    {
        $this->setRedirect(Route::_('index.php?option=com_admin&view=healthcheck', false));
    }
}
