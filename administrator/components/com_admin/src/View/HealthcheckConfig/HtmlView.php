<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_admin
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Admin\Administrator\View\HealthcheckConfig;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\Component\Admin\Administrator\Model\HealthcheckModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Healthcheck Configuration View class for the Admin component
 *
 * @since  5.4
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Available health check providers
     *
     * @var    array
     * @since  5.4
     */
    protected array $providers = [];

    /**
     * Plugin configuration settings
     *
     * @var    array
     * @since  5.4
     */
    protected array $config = [];

    /**
     * Execute and display a template script.
     *
     * @param   string|null  $tpl  The name of the template file to parse
     *
     * @return  void
     *
     * @since   5.4
     *
     * @throws  \Exception
     */
    public function display($tpl = null)
    {
        // Access check.
        if (!$this->getCurrentUser()->authorise('core.admin')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // Load the Health Checker language file
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('com_admin_healthchecker', JPATH_ADMINISTRATOR, null, false, true);

        // Get data from model - direct instantiation
        try {
            $model = new HealthcheckModel();
            if ($model) {
                $this->providers = $model->getHealthCheckProviders();
            } else {
                throw new \Exception('Failed to create HealthcheckModel');
            }
        } catch (\Exception $e) {
            // Log error and provide empty providers array
            Factory::getApplication()->enqueueMessage(
                'Error loading health check providers: ' . $e->getMessage(),
                'error'
            );
            $this->providers = [];
        }
        $this->config = $this->loadPluginConfig();

        $this->addToolbar();
        $this->setupDocument();

        parent::display($tpl);
    }

    /**
     * Setup the Toolbar
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ADMIN_HEALTH_CHECKER_CONFIG'), 'fas fa-cogs');

        $toolbar = $this->getDocument()->getToolbar();

        $toolbar->standardButton('save', 'JSAVE')
            ->icon('fas fa-save')
            ->onclick('Joomla.submitform(\'healthcheckconfig.save\');');

        $toolbar->standardButton('cancel', 'JCANCEL')
            ->icon('fas fa-times')
            ->onclick('Joomla.submitform(\'healthcheckconfig.cancel\');');

        $toolbar->help('Health_Checker_Configuration');
    }

    /**
     * Method to set up the document properties
     *
     * @return  void
     *
     * @since   5.4
     */
    protected function setupDocument()
    {
        /** @var WebAssetManager $wa */
        $wa = $this->getDocument()->getWebAssetManager();

        // Use Bootstrap components for toggles and forms
        $wa->useScript('bootstrap.tab')
           ->useScript('bootstrap.collapse')
           ->useStyle('bootstrap.css');
    }

    /**
     * Load plugin configuration from database/file
     *
     * @return  array  Configuration array
     *
     * @since   5.4
     */
    protected function loadPluginConfig(): array
    {
        $db = Factory::getDbo();

        try {
            // Try to load from database - for now, we'll use a simple approach
            // In production, this might be stored in #__extensions or a custom table
            $query = $db->getQuery(true)
                ->select('params')
                ->from('#__extensions')
                ->where('element = ' . $db->quote('com_admin'))
                ->where('type = ' . $db->quote('component'));

            $db->setQuery($query);
            $paramsJson = $db->loadResult();

            if ($paramsJson) {
                $params = json_decode($paramsJson, true);
                return $params['healthcheck_plugins'] ?? [];
            }
        } catch (Exception $e) {
            // Log error but continue with empty config
            Factory::getApplication()->enqueueMessage(
                'Could not load plugin configuration: ' . $e->getMessage(),
                'warning'
            );
        }

        // Default configuration - all plugins enabled
        $defaultConfig = [];
        foreach ($this->providers as $providerName => $provider) {
            $providerKey = $this->getProviderKey($providerName);
            $defaultConfig[$providerKey] = [
                'enabled' => true,
                'priority' => 10,
                'settings' => []
            ];
        }

        return $defaultConfig;
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
        return strtolower(str_replace(['\\', ' '], ['_', '_'], $provider));
    }

    /**
     * Check if a provider is enabled
     *
     * @param   object  $provider  The provider instance
     *
     * @return  bool  True if enabled
     *
     * @since   5.4
     */
    public function isProviderEnabled($provider): bool
    {
        // Provider is now a provider name string, not an object
        $key = $this->getProviderKey($provider);
        return $this->config[$key]['enabled'] ?? true;
    }

    /**
     * Get provider priority
     *
     * @param   object  $provider  The provider instance
     *
     * @return  int  Priority value
     *
     * @since   5.4
     */
    public function getProviderPriority($provider): int
    {
        // Provider is now a provider name string, not an object
        $key = $this->getProviderKey($provider);
        return $this->config[$key]['priority'] ?? 10;
    }
}
