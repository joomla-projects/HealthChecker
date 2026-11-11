<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Securityheaders
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
use Joomla\Plugin\Healthcheck\Securityheaders\Extension\Securityheaders;
use Joomla\Plugin\Healthcheck\Securityheaders\Headers\HeaderFetcher;

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
            $container->lazy(Securityheaders::class, function (Container $container): Securityheaders {
                $plugin = new Securityheaders(
                    (array) PluginHelper::getPlugin('healthcheck', 'securityheaders'),
                    new HeaderFetcher(new HttpFactory())
                );

                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));
                $plugin->setCacheControllerFactory($container->get(CacheControllerFactoryInterface::class));

                return $plugin;
            })
        );
    }
};
