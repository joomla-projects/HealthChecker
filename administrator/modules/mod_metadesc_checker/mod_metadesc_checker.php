<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  mod_metadesc_checker
 *
 * @copyright   Copyright (C) 2023 Your Name. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

// Include the module helper class
require_once __DIR__ . '/helper.php';

// Get module data from helper
$emptyMetadesc = ModMetadescCheckerHelper::getEmptyMetadescArticles($params);
$duplicateMetadesc = ModMetadescCheckerHelper::getDuplicateMetadescArticles($params);
$globalMetaDesc = ModMetadescCheckerHelper::getGlobalMetaDescription();

// Get parameter for checking meta keywords
$checkKeywords = (int) $params->get('check_keywords', 0);

// Get meta keywords data if enabled
$emptyMetakey = array();
$metakeyByCount = array();

if ($checkKeywords) {
    $emptyMetakey = ModMetadescCheckerHelper::getEmptyMetakeyArticles($params);
    $metakeyByCount = ModMetadescCheckerHelper::getArticlesByKeywordCount($params);
}

// Get access to the application
$app = Factory::getApplication();

// Add necessary scripts and styles
$document = $app->getDocument();
$wa = $document->getWebAssetManager();
$wa->useScript('core')
   ->useScript('jquery')
   ->addInlineScript('
    jQuery(document).ready(function($) {
        $(".metadesc-stats, .metakey-stats").click(function(e) {
            e.preventDefault();
            var target = $(this).data("target");
            var targetElement = $("#" + target);
            
            // Toggle the clicked target
            if (targetElement.is(":visible")) {
                targetElement.hide();
            } else {
                $(".meta-details-list, .keyword-count-details").hide();
                targetElement.show();
            }
            
            return false;
        });
        
        $(".keyword-count-buttons .metakey-stats").click(function(e) {
            e.preventDefault();
            var target = $(this).data("target");
            var targetElement = $("#" + target);
            
            // Toggle the clicked target
            if (targetElement.is(":visible")) {
                targetElement.hide();
            } else {
                $(".keyword-count-details").hide();
                targetElement.show();
            }
            
            return false;
        });
    });
   ');

// Load the layout
require ModuleHelper::getLayoutPath('mod_metadesc_checker', $params->get('layout', 'default'));