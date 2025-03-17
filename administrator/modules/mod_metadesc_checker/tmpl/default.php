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

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

// Initialize counters
$emptyCount = count($emptyMetadesc);
$duplicateCount = 0;
foreach ($duplicateMetadesc as $group) {
    $duplicateCount += count($group);
}

// Initialize meta keyword counters if enabled
$emptyKeywordCount = 0;
$keywordCounts = array();
if ($checkKeywords) {
    $emptyKeywordCount = count($emptyMetakey);
    foreach ($metakeyByCount as $count => $articles) {
        $keywordCounts[$count] = count($articles);
    }
}

// Check if there are issues to report
$hasIssues = ($emptyCount > 0 || $duplicateCount > 0 || 
             ($checkKeywords && ($emptyKeywordCount > 0)));
?>

<div class="card mb-3">
    <div class="card-header">
        <h2>
            <?php echo Text::_('MOD_METADESC_CHECKER_TITLE'); ?>
            <?php if ((int) $params->get('published_only', 1) === 1) : ?>
                <span class="badge bg-info"><?php echo Text::_('JPUBLISHED'); ?></span>
            <?php else : ?>
                <span class="badge bg-secondary"><?php echo Text::_('JALL'); ?></span>
            <?php endif; ?>
        </h2>
    </div>
    <div class="card-body">
        <nav class="quick-icons px-3 pb-3" aria-label="Meta Details">
            <ul class="nav flex-wrap">
                <!-- Global Meta Description -->
                <li class="quickicon quickicon-single">
                    <?php if ($globalMetaDesc['isEmpty'] || $globalMetaDesc['isDefault']) : ?>
                        <a href="index.php?option=com_config" class="danger">
                                                            <div class="quickicon-icon">
                                    <div class="icon-globe" aria-hidden="true"></div>
                                </div>
                            <div class="quickicon-name d-flex align-items-center">
                                <span class="j-links-link">
                                    <?php if ($globalMetaDesc['isEmpty']) : ?>
                                        <?php echo Text::_('MOD_METADESC_CHECKER_GLOBAL_META_EMPTY'); ?>
                                    <?php else : ?>
                                        <?php echo Text::_('MOD_METADESC_CHECKER_GLOBAL_META_DEFAULT'); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </a>
                    <?php else : ?>
                        <a href="index.php?option=com_config" class="success">
                            <div class="quickicon-info">
                                <div class="quickicon-icon">
                                    <div class="icon-globe" aria-hidden="true"></div>
                                </div>
                            </div>
                            <div class="quickicon-name d-flex align-items-center">
                                <span class="j-links-link"><?php echo Text::_('MOD_METADESC_CHECKER_GLOBAL_META_SET'); ?></span>
                            </div>
                        </a>
                    <?php endif; ?>
                </li>
                
                <!-- Article Meta Descriptions -->
                <li class="quickicon quickicon-single">
                    <?php if ($emptyCount > 0 || $duplicateCount > 0) : ?>
                        <a href="#" class="metadesc-stats danger" data-target="article-meta-list">
                                                            <div class="quickicon-icon">
                                    <div class="icon-file-alt" aria-hidden="true"></div>
                                </div>
                            <div class="quickicon-name d-flex align-items-center">
                                <span class="j-links-link">
                                    <?php if ($emptyCount > 0 && $duplicateCount > 0) : ?>
                                        <?php echo Text::_('MOD_METADESC_CHECKER_META_ISSUES'); ?> 
                                        <span class="badge text-dark bg-light"><?php echo $emptyCount + $duplicateCount; ?></span>
                                    <?php elseif ($emptyCount > 0) : ?>
                                        <?php echo Text::_('MOD_METADESC_CHECKER_EMPTY_META'); ?>
                                        <span class="badge text-dark bg-light"><?php echo $emptyCount; ?></span>
                                    <?php else : ?>
                                        <?php echo Text::_('MOD_METADESC_CHECKER_DUPLICATE_META'); ?>
                                        <span class="badge text-dark bg-light"><?php echo $duplicateCount; ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </a>
                    <?php else : ?>
                        <a href="#" class="success">
                            <div class="quickicon-info">
                                <div class="quickicon-icon">
                                    <div class="icon-file-alt" aria-hidden="true"></div>
                                </div>
                            </div>
                            <div class="quickicon-name d-flex align-items-center">
                                <span class="j-links-link"><?php echo Text::_('MOD_METADESC_CHECKER_META_GOOD'); ?></span>
                            </div>
                        </a>
                    <?php endif; ?>
                </li>
                
                <!-- Meta Keywords (if enabled) -->
                <?php if ($checkKeywords) : ?>
                <li class="quickicon quickicon-single">
                    <?php if ($emptyKeywordCount > 0) : ?>
                        <a href="#" class="metakey-stats danger" data-target="keyword-meta-list">
                                                            <div class="quickicon-icon">
                                    <div class="icon-tag" aria-hidden="true"></div>
                                </div>
                            <div class="quickicon-name d-flex align-items-center">
                                <span class="j-links-link">
                                    <?php echo Text::_('MOD_METADESC_CHECKER_EMPTY_KEYWORDS'); ?>
                                    <span class="badge text-dark bg-light"><?php echo $emptyKeywordCount; ?></span>
                                </span>
                            </div>
                        </a>
                    <?php else : ?>
                        <a href="#" class="metakey-stats success" data-target="keyword-count-list">
                            <div class="quickicon-info">
                                <div class="quickicon-icon">
                                    <div class="icon-tag" aria-hidden="true"></div>
                                </div>
                            </div>
                            <div class="quickicon-name d-flex align-items-center">
                                <span class="j-links-link"><?php echo Text::_('MOD_METADESC_CHECKER_KEYWORDS_GOOD'); ?></span>
                            </div>
                        </a>
                    <?php endif; ?>
                </li>
                <?php endif; ?>
            </ul>
        </nav>

        <!-- Detailed Lists (hidden by default) -->
        <div id="article-meta-list" class="meta-details-list" style="display: none;">
            <?php if ($emptyCount > 0) : ?>
                <div class="mb-3">
                    <h3><?php echo Text::_('MOD_METADESC_CHECKER_EMPTY_HEADING'); ?></h3>
                    <ul class="list-group mb-3">
                        <?php foreach ($emptyMetadesc as $article) : ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo htmlspecialchars($article->title, ENT_QUOTES, 'UTF-8'); ?>
                                <a href="<?php echo Route::_('index.php?option=com_content&task=article.edit&id=' . $article->id . '&layout=edit#attrib-publishing'); ?>" target="_blank" class="btn btn-sm btn-primary">
                                    <span class="icon-edit" aria-hidden="true"></span> <?php echo Text::_('JACTION_EDIT'); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if ($duplicateCount > 0) : ?>
                <div class="mb-3">
                    <h3><?php echo Text::_('MOD_METADESC_CHECKER_DUPLICATE_HEADING'); ?></h3>
                    
                    <?php foreach ($duplicateMetadesc as $metadesc => $articles) : ?>
                        <div class="duplicate-group mb-3">
                            <div class="alert alert-danger">
                                <strong><?php echo Text::_('MOD_METADESC_CHECKER_DUPLICATE_META_TEXT'); ?></strong>
                                <p class="small"><?php echo htmlspecialchars($metadesc, ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <ul class="list-group">
                                <?php foreach ($articles as $article) : ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <?php echo htmlspecialchars($article->title, ENT_QUOTES, 'UTF-8'); ?>
                                        <a href="<?php echo Route::_('index.php?option=com_content&task=article.edit&id=' . $article->id . '&layout=edit#attrib-publishing'); ?>" target="_blank" class="btn btn-sm btn-primary">
                                            <span class="icon-edit" aria-hidden="true"></span> <?php echo Text::_('JACTION_EDIT'); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($checkKeywords) : ?>
            <div id="keyword-meta-list" class="meta-details-list" style="display: none;">
                <?php if ($emptyKeywordCount > 0) : ?>
                    <div class="mb-3">
                        <h3><?php echo Text::_('MOD_METADESC_CHECKER_EMPTY_KEYWORDS_HEADING'); ?></h3>
                        <ul class="list-group mb-3">
                            <?php foreach ($emptyMetakey as $article) : ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?php echo htmlspecialchars($article->title, ENT_QUOTES, 'UTF-8'); ?>
                                    <a href="<?php echo Route::_('index.php?option=com_content&task=article.edit&id=' . $article->id . '&layout=edit#attrib-publishing'); ?>" target="_blank" class="btn btn-sm btn-primary">
                                        <span class="icon-edit" aria-hidden="true"></span> <?php echo Text::_('JACTION_EDIT'); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <div id="keyword-count-list" class="meta-details-list" style="display: none;">
                <h3><?php echo Text::_('MOD_METADESC_CHECKER_KEYWORD_COUNT_HEADING'); ?></h3>
                
                <div class="keyword-count-buttons mb-3">
                    <?php foreach ($keywordCounts as $count => $numArticles) : ?>
                        <button type="button" class="metakey-stats btn btn-info m-1" data-target="keyword-count-<?php echo $count; ?>">
                            <?php echo Text::sprintf('MOD_METADESC_CHECKER_KEYWORD_COUNT', $numArticles, $count); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                
                <?php foreach ($metakeyByCount as $count => $articles) : ?>
                    <div id="keyword-count-<?php echo $count; ?>" class="keyword-count-details" style="display: none;">
                        <h4><?php echo Text::sprintf('MOD_METADESC_CHECKER_ARTICLES_WITH_KEYWORDS', $count); ?></h4>
                        <ul class="list-group mb-3">
                            <?php foreach ($articles as $article) : ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?php echo htmlspecialchars($article->title, ENT_QUOTES, 'UTF-8'); ?>
                                    <a href="<?php echo Route::_('index.php?option=com_content&task=article.edit&id=' . $article->id . '&layout=edit#attrib-publishing'); ?>" target="_blank" class="btn btn-sm btn-primary">
                                        <span class="icon-edit" aria-hidden="true"></span> <?php echo Text::_('JACTION_EDIT'); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>