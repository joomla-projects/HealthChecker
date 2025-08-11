<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_admin
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \Joomla\Component\Admin\Administrator\View\Healthcheck\HtmlView $this */

// Helper functions for display
$model = $this->getModel();

// Load accessibility CSS and JS
$wa = \Joomla\CMS\Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->registerAndUseStyle('com_admin.healthcheck-a11y', 'administrator/components/com_admin/assets/css/healthcheck-a11y.css', [], ['version' => 'auto']);
$wa->registerAndUseScript('com_admin.healthcheck-a11y', 'administrator/components/com_admin/assets/js/healthcheck-a11y.js', [], ['version' => 'auto']);

?>

<!-- Skip Links -->
<div class="healthchecker-skip-links">
    <a href="#main-content" class="healthchecker-skip-link">
        Skip to main content
    </a>
    <a href="#extension-table" class="healthchecker-skip-link">
        Skip to extensions table
    </a>
    <a href="#action-buttons" class="healthchecker-skip-link">
        Skip to action buttons
    </a>
</div>

<!-- Live Regions for Dynamic Updates -->
<div id="healthchecker-status-updates" class="healthchecker-live-region" aria-live="polite" aria-atomic="true"></div>
<div id="healthchecker-error-announcements" class="healthchecker-live-region" aria-live="assertive" aria-atomic="true"></div>

<!-- Health Checker Container using Atum/Bootstrap classes -->
<div class="container-fluid" id="main-content">
    <!-- System Messages Container -->
    <div id="system-message-container" aria-live="polite"></div>

    <!-- Page Header using Atum header structure -->
    <div class="row">
        <div class="col-12">
            <div class="page-header mb-4">
                <h1 class="page-title">
                    <span class="icon-heart" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_TITLE'); ?>
                </h1>
                <p class="page-subtitle text-muted"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SUBTITLE'); ?></p>
            </div>
        </div>
    </div>

    <!-- Health Overview Dashboard using Bootstrap grid -->
    <div class="row g-3 mb-4">
        <!-- Health Score Card -->
        <div class="col-lg-4 col-md-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0 h5">
                        <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_OVERALL_HEALTH'); ?>
                        <span class="sr-only">out of 100</span>
                    </h3>
                    <span class="badge bg-<?php echo $this->healthData['status_class']; ?> healthchecker-score-status"
                          role="status" aria-live="polite">
                        <?php echo $this->healthData['status_text']; ?>
                    </span>
                </div>
                <div class="card-body text-center">
                    <div class="healthchecker-score-circle position-relative d-inline-flex align-items-center justify-content-center" 
                         data-score="<?php echo $this->healthData['overall_score']; ?>"
                         role="progressbar" 
                         aria-valuenow="<?php echo $this->healthData['overall_score']; ?>" 
                         aria-valuemin="0" 
                         aria-valuemax="100"
                         aria-label="Health score: <?php echo $this->healthData['overall_score']; ?> out of 100"
                         tabindex="0">
                        <div class="healthchecker-score-value display-4 fw-bold text-primary" aria-hidden="true">
                            <?php echo $this->healthData['overall_score']; ?>
                        </div>
                    </div>
                    <p class="text-muted mt-2"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_HEALTH_SCORE'); ?></p>
                </div>
            </div>
        </div>

        <!-- System Overview Card -->
        <div class="col-lg-4 col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <h3 class="card-title mb-0 h5"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SYSTEM_OVERVIEW'); ?></h3>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <strong><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_JOOMLA_VERSION'); ?></strong><br>
                            <span class="text-muted"><?php echo $this->healthData['joomla_version']; ?></span>
                        </div>
                        <div class="col-6">
                            <strong><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_EXTENSIONS'); ?></strong><br>
                            <span class="text-muted"><?php echo count($this->extensionData); ?> <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_INSTALLED'); ?></span>
                        </div>
                    </div>
                    <hr>
                    <div class="row g-3">
                        <div class="col-6">
                            <strong><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_LAST_SCAN'); ?></strong><br>
                            <span class="text-muted"><?php echo $this->healthData['last_scan']; ?></span>
                        </div>
                        <div class="col-6">
                            <strong><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_TARGET_VERSION'); ?></strong><br>
                            <span class="text-primary"><?php echo $this->healthData['target_version']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Critical Issues Card -->
        <div class="col-lg-4 col-md-12">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0 h5"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_CRITICAL_ISSUES'); ?></h3>
                    <span class="badge bg-warning d-flex align-items-center gap-1">
                        <span class="icon-warning" aria-hidden="true"></span>
                        <?php echo count($this->criticalIssues); ?> <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_ISSUES'); ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php foreach ($this->criticalIssues as $issue) : ?>
                        <div class="alert alert-<?php echo $issue['severity']; ?> py-2 mb-2">
                            <strong><?php echo $issue['title']; ?>:</strong>
                            <?php echo $issue['description']; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Plan Section -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">
                    <h3 class="card-title mb-0 h5 d-flex align-items-center gap-2">
                        <span class="icon-list" aria-hidden="true"></span>
                        <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_UPGRADE_RECOMMENDATIONS'); ?>
                    </h3>
                </div>
                <div class="card-body">
                    <?php
                    $recommendationLevels = ['critical', 'medium', 'ready'];
                    $levelNames = [
                        Text::_('COM_ADMIN_HEALTH_CHECKER_CRITICAL_FIRST'),
                        Text::_('COM_ADMIN_HEALTH_CHECKER_MEDIUM_PRIORITY'),
                        Text::_('COM_ADMIN_HEALTH_CHECKER_READY_TO_PROCEED')
                    ];
                    $levelClasses = ['danger', 'warning', 'success'];

                    foreach ($recommendationLevels as $index => $level) :
                        $levelRecommendations = $this->recommendations[$level] ?? [];
                        if (!empty($levelRecommendations)) : ?>
                            <div class="healthchecker-expandable mb-3">
                                <div class="healthchecker-expandable-header d-flex justify-content-between align-items-center p-2 border rounded cursor-pointer">
                                    <h5 class="text-<?php echo $levelClasses[$index]; ?> mb-0"><?php echo $levelNames[$index]; ?></h5>
                                    <span class="icon-chevron-down"></span>
                                </div>
                                <div class="healthchecker-expandable-content mt-2" style="display: none;">
                                    <ul class="list-unstyled">
                                        <?php foreach ($levelRecommendations as $recommendation) : ?>
                                            <li class="mb-1 d-flex align-items-start gap-2">
                                                <span class="icon-check text-<?php echo $levelClasses[$index]; ?> mt-1" aria-hidden="true"></span>
                                                <?php echo $recommendation; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Core Health Checks -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="card-title mb-0 h5 d-flex align-items-center gap-2">
                        <span class="icon-shield" aria-hidden="true"></span>
                        <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_CORE_HEALTH_CHECKS'); ?>
                    </h3>
                    <span class="badge bg-success d-flex align-items-center gap-1">
                        <span class="icon-check" aria-hidden="true"></span>
                        <?php echo $this->healthData['core_checks_passed'] . '/' . $this->healthData['core_checks_total']; ?> <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_PASSED'); ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php foreach ($this->healthData['core_checks'] as $checkType => $checkData) : ?>
                        <div class="check-section mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0"><?php echo ucfirst(str_replace('_', ' ', $checkType)) . ' ' . Text::_('COM_ADMIN_HEALTH_CHECKER_CHECKS'); ?></h6>
                                <span class="badge bg-<?php echo $checkData['status']; ?> d-flex align-items-center gap-1">
                                    <?php
                                    $statusIcons = ['success' => 'check', 'warning' => 'warning', 'danger' => 'times'];
                                    $icon = $statusIcons[$checkData['status']] ?? 'question';
                                    ?>
                                    <span class="icon-<?php echo $icon; ?>" aria-hidden="true"></span>
                                    <?php echo ($checkData['status'] === 'success' ? Text::_('COM_ADMIN_HEALTH_CHECKER_PASS') : Text::_('COM_ADMIN_HEALTH_CHECKER_REVIEW')); ?>
                                </span>
                            </div>

                            <?php if (isset($checkData['details'])) : ?>
                                <?php foreach ($checkData['details'] as $detail => $value) : ?>
                                    <div class="check-detail d-flex justify-content-between align-items-center mb-1">
                                        <span class="text-muted small"><?php echo ucfirst(str_replace('_', ' ', $detail)); ?></span>
                                        <?php if (is_bool($value)) : ?>
                                            <span class="text-<?php echo $value ? 'success' : 'danger'; ?>">
                                                <span class="icon-<?php echo $value ? 'check' : 'times'; ?>" aria-hidden="true"></span>
                                            </span>
                                        <?php elseif (is_numeric($value)) : ?>
                                            <span class="text-<?php echo $value >= 80 ? 'success' : 'warning'; ?>"><?php echo $value; ?>%</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (is_numeric($value)) : ?>
                                        <div class="progress mb-2" style="height: 4px;">
                                            <div class="progress-bar bg-<?php echo $value >= 80 ? 'success' : 'warning'; ?>" 
                                                 style="width: <?php echo $value; ?>%" role="progressbar" 
                                                 aria-valuenow="<?php echo $value; ?>" aria-valuemin="0" aria-valuemax="100">
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Extension Compatibility Analysis -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0 h5 d-flex align-items-center gap-2">
                <span class="icon-puzzle" aria-hidden="true"></span>
                <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_EXTENSION_COMPATIBILITY'); ?>
            </h3>
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="healthChecker.exportReport()">
                    <span class="icon-download" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_EXPORT_REPORT'); ?>
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="healthChecker.runFullScan()">
                    <span class="icon-refresh" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_RUN_FULL_SCAN'); ?>
                </button>
            </div>
        </div>

        <!-- Filter Tabs using Bootstrap nav-tabs -->
        <div class="card-body">
            <ul class="nav nav-tabs mb-3" role="tablist" aria-label="Filter extensions by status">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-filter="all" type="button" role="tab"
                            id="tab-all" aria-controls="extension-table" aria-selected="true">
                        <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_ALL_EXTENSIONS'); ?>
                        <span class="badge bg-secondary ms-1"><?php echo count($this->extensionData); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-filter="incompatible" type="button" role="tab"
                            id="tab-incompatible" aria-controls="extension-table" aria-selected="false">
                        <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_INCOMPATIBLE'); ?>
                        <span class="badge bg-secondary ms-1"><?php echo $model->countByStatus($this->extensionData, 'incompatible'); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-filter="needs_update" type="button" role="tab"
                            id="tab-needs-update" aria-controls="extension-table" aria-selected="false">
                        <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_NEEDS_UPDATE'); ?>
                        <span class="badge bg-secondary ms-1"><?php echo $model->countByStatus($this->extensionData, 'needs_update'); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-filter="compatible" type="button" role="tab"
                            id="tab-compatible" aria-controls="extension-table" aria-selected="false">
                        <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_COMPATIBLE'); ?>
                        <span class="badge bg-secondary ms-1"><?php echo $model->countByStatus($this->extensionData, 'compatible'); ?></span>
                    </button>
                </li>
            </ul>

            <!-- Extensions Table -->
            <?php echo $this->loadTemplate('extensions'); ?>
        </div>
    </div>

    <!-- Bottom Action Bar -->
    <div class="card" id="action-buttons">
        <div class="card-body text-center">
            <div class="btn-toolbar justify-content-center gap-2" role="toolbar">
                <button type="button" class="btn btn-success btn-lg" onclick="healthChecker.startUpgradeProcess()">
                    <span class="icon-rocket" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_START_UPGRADE'); ?>
                </button>
                <button type="button" class="btn btn-outline-primary" onclick="healthChecker.generateDetailedReport()">
                    <span class="icon-chart" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_GENERATE_REPORT'); ?>
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="healthChecker.rerunHealthCheck()">
                    <span class="icon-refresh" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_RERUN_CHECK'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
