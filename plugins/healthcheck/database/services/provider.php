<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
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
use Joomla\Plugin\Healthcheck\Database\Extension\Database;
use Joomla\Plugin\Healthcheck\Database\Ignore\IgnoreRepository;
use Joomla\Plugin\Healthcheck\Database\Ownership\TableOwnership;
use Joomla\Plugin\Healthcheck\Database\Scan\ResultStore;

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
            $container->lazy(Database::class, function (Container $container): Database {
                $db     = $container->get(DatabaseInterface::class);
                $plugin = new Database(
                    (array) PluginHelper::getPlugin('healthcheck', 'database'),
                    new IgnoreRepository($db),
                    new ResultStore($container->get(CacheControllerFactoryInterface::class)),
                    new TableOwnership(JPATH_ROOT, JPATH_ADMINISTRATOR)
                );

                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($db);

                return $plugin;
            })
        );
    }
};
