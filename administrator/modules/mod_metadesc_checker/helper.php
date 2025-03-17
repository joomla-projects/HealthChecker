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

use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;

/**
 * Helper class for Metadesc Checker module
 */
class ModMetadescCheckerHelper
{
    /**
     * Get all articles with empty metadesc
     *
     * @param   \Joomla\Registry\Registry  $params  Module parameters
     * @return  array  Articles with empty metadesc
     */
    public static function getEmptyMetadescArticles($params)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        
        // Check if we should filter by published state
        $publishedOnly = (int) $params->get('published_only', 1);
        
        // Select articles with empty metadesc
        $query->select($db->quoteName(['id', 'title', 'alias', 'catid', 'state']))
              ->from($db->quoteName('#__content'))
              ->where('(' . $db->quoteName('metadesc') . ' = ' . $db->quote('') . 
                     ' OR ' . $db->quoteName('metadesc') . ' IS NULL)');
                     
        // Add published filter if needed
        if ($publishedOnly) {
            $query->where($db->quoteName('state') . ' = 1');
        }
        
        $query->order($db->quoteName('title') . ' ASC');
              
        $db->setQuery($query);
        
        try {
            $results = $db->loadObjectList();
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return array();
        }
        
        return $results;
    }
    
    /**
     * Get all articles with duplicate metadesc
     *
     * @param   \Joomla\Registry\Registry  $params  Module parameters
     * @return  array  Articles with duplicate metadesc
     */
    public static function getDuplicateMetadescArticles($params)
    {
        $db = Factory::getDbo();
        
        // Check if we should filter by published state
        $publishedOnly = (int) $params->get('published_only', 1);
        
        // This query requires two steps
        // First, find all metadesc values that appear more than once
        $query1 = $db->getQuery(true);
        $query1->select($db->quoteName('metadesc'))
               ->from($db->quoteName('#__content'))
               ->where($db->quoteName('metadesc') . ' != ' . $db->quote(''))
               ->where($db->quoteName('metadesc') . ' IS NOT NULL');
               
        // Add published filter if needed
        if ($publishedOnly) {
            $query1->where($db->quoteName('state') . ' = 1');
        }
               
        $query1->group($db->quoteName('metadesc'))
               ->having('COUNT(' . $db->quoteName('metadesc') . ') > 1');
        
        $db->setQuery($query1);
        
        try {
            $duplicateMetadescs = $db->loadColumn();
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return array();
        }
        
        if (empty($duplicateMetadescs)) {
            return array();
        }
        
        // Second, get all articles using these duplicate metadescs
        $query2 = $db->getQuery(true);
        $query2->select($db->quoteName(['id', 'title', 'alias', 'catid', 'metadesc', 'state']))
               ->from($db->quoteName('#__content'))
               ->where($db->quoteName('metadesc') . ' IN (' . 
                      implode(',', array_map(array($db, 'quote'), $duplicateMetadescs)) . ')');
               
        // Add published filter if needed
        if ($publishedOnly) {
            $query2->where($db->quoteName('state') . ' = 1');
        }
               
        $query2->order($db->quoteName('metadesc') . ' ASC, ' . $db->quoteName('title') . ' ASC');
        
        $db->setQuery($query2);
        
        try {
            $results = $db->loadObjectList();
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return array();
        }
        
        // Group results by metadesc
        $grouped = array();
        foreach ($results as $item) {
            if (!isset($grouped[$item->metadesc])) {
                $grouped[$item->metadesc] = array();
            }
            $grouped[$item->metadesc][] = $item;
        }
        
        return $grouped;
    }
    
    /**
     * Get all articles with empty metakey
     *
     * @param   \Joomla\Registry\Registry  $params  Module parameters
     * @return  array  Articles with empty metakey
     */
    public static function getEmptyMetakeyArticles($params)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        
        // Check if we should filter by published state
        $publishedOnly = (int) $params->get('published_only', 1);
        
        // Select articles with empty metakey
        $query->select($db->quoteName(['id', 'title', 'alias', 'catid', 'state']))
              ->from($db->quoteName('#__content'))
              ->where('(' . $db->quoteName('metakey') . ' = ' . $db->quote('') . 
                     ' OR ' . $db->quoteName('metakey') . ' IS NULL)');
              
        // Add published filter if needed
        if ($publishedOnly) {
            $query->where($db->quoteName('state') . ' = 1');
        }
              
        $query->order($db->quoteName('title') . ' ASC');
              
        $db->setQuery($query);
        
        try {
            $results = $db->loadObjectList();
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return array();
        }
        
        return $results;
    }
    
    /**
     * Get articles grouped by number of keywords
     *
     * @param   \Joomla\Registry\Registry  $params  Module parameters
     * @return  array  Articles grouped by keyword count
     */
    public static function getArticlesByKeywordCount($params)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        
        // Check if we should filter by published state
        $publishedOnly = (int) $params->get('published_only', 1);
        
        // Select articles with their metakey
        $query->select($db->quoteName(['id', 'title', 'alias', 'catid', 'metakey', 'state']))
              ->from($db->quoteName('#__content'))
              ->where($db->quoteName('metakey') . ' != ' . $db->quote(''))
              ->where($db->quoteName('metakey') . ' IS NOT NULL');
              
        // Add published filter if needed
        if ($publishedOnly) {
            $query->where($db->quoteName('state') . ' = 1');
        }
              
        $query->order($db->quoteName('title') . ' ASC');
              
        $db->setQuery($query);
        
        try {
            $results = $db->loadObjectList();
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return array();
        }
        
        // Group results by number of keywords
        $grouped = array();
        foreach ($results as $item) {
            // Count keywords by splitting on common delimiters and filtering empty values
            $keywords = preg_split('/[,;]+/', $item->metakey);
            $keywords = array_filter(array_map('trim', $keywords), 'strlen');
            $count = count($keywords);
            
            if (!isset($grouped[$count])) {
                $grouped[$count] = array();
            }
            $grouped[$count][] = $item;
        }
        
        // Sort by key (keyword count) numerically
        ksort($grouped, SORT_NUMERIC);
        
        return $grouped;
    }
    
    /**
     * Get the global meta description from configuration
     * 
     * @return  array  Array with status information
     */
    public static function getGlobalMetaDescription()
    {
        $config = Factory::getApplication()->getConfig();
        $metaDesc = $config->get('MetaDesc', '');
        $defaultJoomlaDesc = "Joomla! - the dynamic portal engine and content management system";
        
        return array(
            'value' => $metaDesc,
            'isEmpty' => empty($metaDesc),
            'isDefault' => (trim($metaDesc) === $defaultJoomlaDesc)
        );
    }
}