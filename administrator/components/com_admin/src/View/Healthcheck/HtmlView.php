<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_admin
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Admin\Administrator\View\Healthcheck;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\WebAsset\WebAssetManager;
use Joomla\Component\Admin\Administrator\Model\HealthcheckModel;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Healthcheck View class for the Admin component
 *
 * @since  5.4
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Health data for the system
     *
     * @var    array
     * @since  5.4
     */
    protected array $healthData = [];

    /**
     * Extension compatibility data
     *
     * @var    array
     * @since  5.4
     */
    protected array $extensionData = [];

    /**
     * Critical issues found during health check
     *
     * @var    array
     * @since  5.4
     */
    protected array $criticalIssues = [];

    /**
     * Recommendations based on health check results
     *
     * @var    array
     * @since  5.4
     */
    protected array $recommendations = [];

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

        /** @var HealthcheckModel $model */
        $model                  = $this->getModel();
        $this->healthData       = $model->getHealthData();
        $this->extensionData    = $model->getExtensionData();
        $this->criticalIssues   = $model->getCriticalIssues();
        $this->recommendations  = $model->getRecommendations();

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
        ToolbarHelper::title(Text::_('COM_ADMIN_HEALTH_CHECKER'), 'fas fa-heartbeat');

        $toolbar = $this->getDocument()->getToolbar();

        $toolbar->standardButton('refresh', 'COM_ADMIN_HEALTH_CHECKER_RUN_SCAN')
            ->icon('icon-refresh')
            ->onclick('Joomla.submitbutton(\'healthcheck.scan\');');

        $toolbar->linkButton('download', 'COM_ADMIN_HEALTH_CHECKER_EXPORT_REPORT')
            ->icon('icon-download')
            ->url(Route::_('index.php?option=com_admin&view=healthcheck&format=json&' . Session::getFormToken() . '=1'));

        $toolbar->help('Health_Checker');
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

        // Register and use HealthChecker assets
        $wa->registerAndUseScript('com_admin.healthcheck', 'administrator/components/com_admin/assets/js/healthcheck.js', [], ['defer' => true])
           ->registerAndUseStyle('com_admin.healthcheck', 'administrator/components/com_admin/assets/css/healthcheck.css');

        // Use modern Bootstrap 5 features
        $wa->useScript('bootstrap.tab')
           ->useScript('bootstrap.modal');
    }
}
