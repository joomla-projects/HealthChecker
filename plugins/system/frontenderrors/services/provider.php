<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.Frontenderrors
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Plugin\Healthcheck\Frontenderrors\Model\ErrorRepository;
use Joomla\Plugin\System\Frontenderrors\Extension\Frontenderrors;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                // The repository is only used while the Health Check plugin is enabled.
                $repository = PluginHelper::isEnabled('healthcheck', 'frontenderrors')
                    ? new ErrorRepository($container->get(DatabaseInterface::class))
                    : null;

                $plugin = new Frontenderrors(
                    (array) PluginHelper::getPlugin('system', 'frontenderrors'),
                    $repository
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
