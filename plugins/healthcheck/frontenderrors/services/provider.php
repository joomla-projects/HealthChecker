<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Frontenderrors
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
use Joomla\Plugin\Healthcheck\Frontenderrors\Extension\Frontenderrors;
use Joomla\Plugin\Healthcheck\Frontenderrors\Model\ErrorRepository;
use Joomla\Plugin\Healthcheck\Frontenderrors\Model\ScanPages;

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
            $container->lazy(Frontenderrors::class, function (Container $container): Frontenderrors {
                $db     = $container->get(DatabaseInterface::class);
                $plugin = new Frontenderrors(
                    (array) PluginHelper::getPlugin('healthcheck', 'frontenderrors'),
                    new ErrorRepository($db),
                    new ScanPages($db)
                );

                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($db);

                return $plugin;
            })
        );
    }
};
