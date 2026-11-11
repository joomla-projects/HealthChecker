<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Siteinfo
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Http\HttpFactory;
use Joomla\Plugin\Healthcheck\Siteinfo\Extension\Siteinfo;

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
            $container->lazy(Siteinfo::class, function (Container $container): Siteinfo {
                $plugin = new Siteinfo(
                    (array) PluginHelper::getPlugin('healthcheck', 'siteinfo'),
                    new HttpFactory()
                );

                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));
                $plugin->setCacheControllerFactory($container->get(CacheControllerFactoryInterface::class));

                return $plugin;
            })
        );
    }
};
