<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Quickicon.phpversioncheck
 *
 * @copyright   (C) 2024 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Quickicon\EndOfLife\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Module\Quickicon\Administrator\Event\QuickIconsEvent;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Plugin to check if there is a new version available
 *
 * @since   __DEPLOY_VERSION__
 */
final class EndOfLife extends CMSPlugin implements SubscriberInterface
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_SECURITY_ONLY = 2;
    public const STATUS_UNSUPPORTED = 0;

    /**
     * Load plugin language files automatically
     *
     * @var    boolean
     * @since  __DEPLOY_VERSION__
     */
    protected $autoloadLanguage = true;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onGetIcons' => 'onGetIcons',
        ];
    }

    /*
        The kind of result that gets returned:

        {
          "cycle": "8.2",
          "releaseDate": "2022-12-08",
          "eol": "2026-12-31",
          "latest": "8.2.24",
          "latestReleaseDate": "2024-09-26",
          "lts": false,
          "support": "2024-12-31"
        }
     */
    protected function getSingleCycle($product, $version)
    {
        $cache = Factory::getCache('plg_quickicon_endoflife', '');
        $cache->setCaching(true);
        $cache->setLifeTime(3600); // Cache in minutes

        $cacheid = md5('plg_quickicon_endoflife_' . $product); // Must be unique by value

        if ($cache->contains($cacheid)) {
            return $cache->get($cacheid);
        }

        if ($this->isLocalhost()) {
            // Temporary disable SSL verification for local testing

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $response = file_get_contents('https://endoflife.date/api/' . $product . '/' . $version . '.json', false, $context);
        } else {
            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL => 'http://endoflife.date/api/' . $product . '/' . $version . '.json',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json'
                ],
            ]);

            $response = curl_exec($curl);
            $error = curl_error($curl);

            curl_close($curl);

            if ($error) {
                return false;
            }
        }        

        $cache->store($response, $cacheid);

        return $response;
    }

    /**
     * 
     * @param unknown $productDetails
     * @param unknown $product
     * @return number
     */
    protected function getStatus($productDetails, $product)
    {
        $today = new Date();

//         if ($productDetails['lts'] !== false) {
//             return self::STATUS_ACTIVE;
//         }

        if (new Date($productDetails['support']) > $today) {
            return self::STATUS_ACTIVE;
        }

        if ($product == 'php' && new Date($productDetails['eol']) > $today) {
            return self::STATUS_SECURITY_ONLY;
        }

        return self::STATUS_UNSUPPORTED;
    }

    /**
     * Check the PHP version after the admin component has been dispatched.
     *
     * @param   QuickIconsEvent  $event  The event object
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    public function onGetIcons(QuickIconsEvent $event): void
    {
        $context = $event->getContext();

        if (
            $context !== $this->params->get('context', 'update_quickicon')
            || !$this->getApplication()->getIdentity()->authorise('core.admin', 'com_admin')
        ) {
            return;
        }

        // Get the system information.

        $factory = $this->getApplication()->bootComponent('com_admin')->getMVCFactory();
        $model = $factory->createModel('Sysinfo', 'Administrator', ['ignore_request' => true]);
        $information = $model->getInfo();

        $information['dbserver'] = strtolower($information['dbserver']);
        $information['dbversion'] = explode('.', $information['dbversion']);

        $database_names = [
            'mariadb'    => 'MariaDB',
            'mysql'      => 'MySQL',
            'postgresql' => 'PostgreSQL',
        ];

        // Add the icon to the result array
        $result = $event->getArgument('result', []);

        $products = [
            'php' => [
                'name'    => 'PHP',
                'version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'url'     => 'index.php?option=com_admin&view=sysinfo',
                'icon'    => 'fa-brands fa-php',
            ],
            $information['dbserver'] => [
                'name'    => $database_names[$information['dbserver']],
                'version' => $information['dbversion'][0] . '.' . $information['dbversion'][1],
                'url'     => 'https://endoflife.date/' . $information['dbserver'],
                'icon'    => 'fas fa-database',
            ],
        ];

        $useAjax = false;

        // When using Ajax
        if ($useAjax) {
            $wa = $this->getApplication()->getDocument()->getWebAssetManager();
            $wa->registerAndUseScript('plg_quickicon_endoflife', 'plg_quickicon_endoflife/endoflife.js', [], ['defer' => true], ['core']);
        }

        foreach ($products as $product => $productInfo) {

            if (empty($productInfo['version'])) {
                continue;
            }

            if ($useAjax) {
                Text::script('PLG_QUICKICON_ENDOFLIFE_STATUS_UNKNOWN');
                Text::script('PLG_QUICKICON_ENDOFLIFE_STATUS_ACTIVE');
                Text::script('PLG_QUICKICON_ENDOFLIFE_STATUS_SECURITY_ONLY');
                Text::script('PLG_QUICKICON_ENDOFLIFE_STATUS_UNSUPPORTED');

                $options  = [
                    'key'     => $product,
                    'version' => $productInfo['version'],
                ];

                $this->getApplication()->getDocument()->addScriptOptions('js-endoflife-check', $options);

                $statusText = $this->getApplication()->getLanguage()->_('PLG_QUICKICON_ENDOFLIFE_CHECKING');
                $statusClass = '';
            } else {
                // When not using Ajax

                $productDetails = $this->getSingleCycle($product, $productInfo['version']);

                if ($productDetails === false) {
                    return;
                }

                $productDetails = json_decode($productDetails, true);

                if ($productDetails === null) {
                    return;
                }

                $status = $this->getStatus($productDetails, $product);

                switch ($status) {
                    case self::STATUS_ACTIVE:
                        $statusText = Text::sprintf('PLG_QUICKICON_ENDOFLIFE_STATUS_ACTIVE', $productInfo['name']);
                        $statusClass = 'success';
                        break;
                    case self::STATUS_SECURITY_ONLY:
                        $statusText = Text::sprintf('PLG_QUICKICON_ENDOFLIFE_STATUS_SECURITY_ONLY', $productInfo['name'], (new Date($productDetails['eol']))->format(Text::_('DATE_FORMAT_LC4')));
                        $statusClass = 'success';
                        
                        // If the version is still supported, check if it has reached eol minus 3 month
                        $securityWarningDate = new Date($productDetails['eol']);
                        $securityWarningDate->sub(new \DateInterval('P3M'));
                        if (new Date() > $securityWarningDate) {
                            $statusClass = 'warning';
                        }
                        break;
                    case self::STATUS_UNSUPPORTED:
                        $statusText = Text::sprintf('PLG_QUICKICON_ENDOFLIFE_STATUS_UNSUPPORTED', $productInfo['name']);
                        $statusClass = 'danger';
                        break;
                    default:
                        $statusText = Text::sprintf('PLG_QUICKICON_ENDOFLIFE_STATUS_UNKNOWN', $productInfo['name']);
                }
            }

            $result[] = [
                [
                    'link'  => $productInfo['url'],
                    'image' => $productInfo['icon'],
                    'icon'  => '',
                    'text'  => $statusText,
                    'class' => $statusClass,
                    'id'    => 'plg_quickicon_endoflife_' . $product,
                    'group' => 'MOD_QUICKICON_MAINTENANCE',
                ],
            ];
        }

        $event->setArgument('result', $result);
    }

    /**
     * Determine if the site is installed in a local environmnent
     * 
     * @return bool
     */
    protected function isLocalhost(): bool
    {
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        return in_array($serverName, ['localhost', '127.0.0.1'], true)
        || in_array($remoteAddr, ['127.0.0.1', '::1'], true);
    }
}
