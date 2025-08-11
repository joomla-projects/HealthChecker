<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_admin
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;

/** @var \Joomla\Component\Admin\Administrator\View\HealthcheckConfig\HtmlView $this */

// Load web assets
HTMLHelper::_('bootstrap.tab');
HTMLHelper::_('bootstrap.collapse');
// Note: jQuery UI is not available in Joomla 5.x, using native HTML5 drag/drop fallback

// Add inline CSS for healthcheck config (following Joomla conventions)
$document = Factory::getApplication()->getDocument();
$document->addStyleDeclaration('
.healthcheck-config {
    /* Table column widths */
    .col-reorder {
        width: 80px;
        min-width: 80px;
    }
    
    /* Priority input styling */
    .priority-input {
        width: 80px;
        max-width: 80px;
    }
    
    /* Drag and drop styling */
    .drag-handle {
        cursor: move;
        color: #6c757d;
        transition: color 0.15s ease-in-out;
    }
    
    .drag-handle:hover {
        color: #495057;
        background-color: #f8f9fa;
        border-radius: 0.25rem;
    }
    
    /* Sortable UI styling */
    .sortable-placeholder {
        background-color: #f8f9fa !important;
        border: 2px dashed #dee2e6 !important;
        height: 60px;
        opacity: 0.8;
    }
    
    .sortable-helper {
        background-color: #fff !important;
        box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important;
        z-index: 1000;
    }
    
    .provider-row.ui-sortable-helper {
        background-color: #ffffff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    
    /* Focus indicators for accessibility */
    .provider-row:focus {
        outline: 2px solid #0d6efd;
        outline-offset: 2px;
        background-color: #f8f9fa;
    }
    
    .move-up-btn:focus,
    .move-down-btn:focus {
        box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
    }
    
    /* Form validation styling */
    .is-invalid {
        border-color: #dc3545 !important;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
    }
    
    /* Reorder controls */
    .reorder-controls {
        text-align: center;
        vertical-align: middle;
    }
    
    .btn-group-vertical .btn {
        margin-bottom: 2px;
    }
    
    /* High contrast mode support */
    @media (prefers-contrast: high) {
        .drag-handle {
            border: 1px solid currentColor;
        }
        
        .provider-row:focus {
            outline: 3px solid;
            background-color: highlight;
            color: highlighttext;
        }
    }
    
    /* Reduced motion support */
    @media (prefers-reduced-motion: reduce) {
        .drag-handle,
        .sortable-helper {
            transition: none;
        }
    }
}
');

?>

<div class="container-fluid healthcheck-config">
    <!-- System Messages Container -->
    <div id="system-message-container" aria-live="polite"></div>

    <!-- Page Header -->
    <div class="row">
        <div class="col-12">
            <div class="page-header mb-4">
                <h1 class="page-title">
                    <span class="icon-cogs" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_CONFIG'); ?>
                </h1>
                <p class="page-subtitle text-muted"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_CONFIG_DESC'); ?></p>
            </div>
        </div>
    </div>

    <form action="<?php echo JRoute::_('index.php?option=com_admin&view=healthcheckconfig'); ?>" method="post" name="adminForm" id="adminForm">
        
        <!-- Available Health Check Providers -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title mb-0 h5 d-flex align-items-center gap-2">
                    <span class="icon-puzzle" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_INSTALLED_PROVIDERS'); ?>
                </h3>
            </div>
            <div class="card-body">
                <?php if (empty($this->providers)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_NO_PROVIDERS_FOUND'); ?>
                    </div>
                <?php else : ?>
                    <!-- Provider Categories Tabs -->
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <?php
                        $categories = [];
                        foreach ($this->providers as $providerName => $provider) {
                            $category = ($provider[0]['category'] ?? 'unknown');
                            if (!isset($categories[$category])) {
                                $categories[$category] = [];
                            }
                            $categories[$category][$providerName] = $provider;
                        }
                        $firstCategory = true;
                        ?>
                        <?php foreach ($categories as $category => $categoryProviders) : ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $firstCategory ? 'active' : ''; ?>" 
                                        id="<?php echo $category; ?>-tab" 
                                        data-bs-toggle="tab" 
                                        data-bs-target="#<?php echo $category; ?>-panel" 
                                        type="button" 
                                        role="tab" 
                                        aria-controls="<?php echo $category; ?>-panel" 
                                        aria-selected="<?php echo $firstCategory ? 'true' : 'false'; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $category)); ?> (<?php echo count($categoryProviders); ?>)
                                </button>
                            </li>
                            <?php $firstCategory = false; ?>
                        <?php endforeach; ?>
                    </ul>

                    <!-- Tab Content -->
                    <div class="tab-content">
                        <?php
                        $firstCategory = true;
                        foreach ($categories as $category => $categoryProviders) : ?>
                            <div class="tab-pane fade <?php echo $firstCategory ? 'show active' : ''; ?>" 
                                 id="<?php echo $category; ?>-panel" 
                                 role="tabpanel" 
                                 aria-labelledby="<?php echo $category; ?>-tab">
                                
                                <div class="mb-3">
                                    <div class="alert alert-info" role="region" aria-labelledby="reorder-instructions">
                                        <span class="icon-info-circle" aria-hidden="true"></span>
                                        <span id="reorder-instructions">
                                            <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_DRAG_TO_REORDER'); ?>
                                            <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_KEYBOARD_REORDER'); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- Screen reader live region for announcements -->
                                <div id="provider-announcements-<?php echo $category; ?>" 
                                     class="visually-hidden" 
                                     aria-live="polite" 
                                     aria-atomic="true">
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th scope="col" class="col-reorder"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_ORDER'); ?></th>
                                                <th scope="col"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_PROVIDER_NAME'); ?></th>
                                                <th scope="col"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_TYPE'); ?></th>
                                                <th scope="col"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_STATUS'); ?></th>
                                                <th scope="col"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_PRIORITY'); ?></th>
                                                <th scope="col" class="text-center"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_ENABLED'); ?></th>
                                                <th scope="col"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_ACTIONS'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody class="sortable-providers" data-category="<?php echo $category; ?>">
                                            <?php
                                            // Sort providers by priority
                                            // Sort by provider name for now (no priority needed for discovery system)
                                            ksort($categoryProviders);
                                            ?>
                                            <?php foreach ($categoryProviders as $providerName => $provider) : ?>
                                                <?php
                                                // Provider is now an array of health check data, not an object
                                                $providerKey = $providerName;
                                                $isEnabled = true; // All discovered providers are enabled
                                                $priority = 1;
                                                $metadata = ['version' => '1.0.0', 'type' => 'discovered'];
                                                $displayFormat = 'list';

                                                // Determine provider type based on provider name
                                                $providerType = 'Plugin';
                                                if (strpos($providerName, 'mod_') === 0) {
                                                    $providerType = 'Module';
                                                } elseif (strpos($providerName, '_') !== false) {
                                                    $providerType = 'Plugin';
                                                } else {
                                                    $providerType = 'Discovered Provider';
                                                }
                                                ?>
                                                <tr class="provider-row" data-provider="<?php echo $providerKey; ?>" data-provider-name="<?php echo htmlspecialchars($providerName); ?>" tabindex="0" role="row" aria-describedby="provider-help-<?php echo $providerKey; ?>">
                                                    <td class="reorder-controls">
                                                        <!-- Keyboard accessible reorder buttons -->
                                                        <div class="btn-group-vertical btn-group-sm" role="group" aria-label="Reorder <?php echo htmlspecialchars($providerName); ?>">
                                                            <button type="button" 
                                                                    class="btn btn-outline-secondary btn-sm move-up-btn" 
                                                                    onclick="healthChecker.moveProvider('<?php echo $providerKey; ?>', 'up')"
                                                                    aria-label="Move <?php echo htmlspecialchars($providerName); ?> up"
                                                                    title="Move up">
                                                                <span class="icon-chevron-up" aria-hidden="true"></span>
                                                            </button>
                                                            <button type="button" 
                                                                    class="btn btn-outline-secondary btn-sm move-down-btn" 
                                                                    onclick="healthChecker.moveProvider('<?php echo $providerKey; ?>', 'down')"
                                                                    aria-label="Move <?php echo htmlspecialchars($providerName); ?> down"
                                                                    title="Move down">
                                                                <span class="icon-chevron-down" aria-hidden="true"></span>
                                                            </button>
                                                        </div>
                                                        <!-- Drag handle for mouse users -->
                                                        <div class="drag-handle text-center mt-1" 
                                                             tabindex="-1"
                                                             aria-hidden="true">
                                                            <span class="icon-menu" title="Drag to reorder"></span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="d-flex align-items-center gap-2">
                                                            <span class="icon-<?php echo $category === 'seo' ? 'search' : ($category === ($provider[0]['category'] ?? 'unknown') ? 'cog' : 'plugin'); ?>" aria-hidden="true"></span>
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($providerName); ?></strong>
                                                                <?php if (!empty($metadata['description'])) : ?>
                                                                    <br><small class="text-muted"><?php echo htmlspecialchars($metadata['description']); ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $providerType === 'Module Adapter' ? 'info' : 'primary'; ?>">
                                                            <?php echo $providerType; ?>
                                                        </span>
                                                        <?php if ($displayFormat !== 'status') : ?>
                                                            <br><small class="text-muted">Format: <?php echo $displayFormat; ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $checks = $provider;
                                                        $hasErrors = false;
                                                        $hasWarnings = false;
                                                        foreach ($checks as $check) {
                                                            if ($check['status'] === 'error') {
                                                                $hasErrors = true;
                                                            }
                                                            if ($check['status'] === 'warning') {
                                                                $hasWarnings = true;
                                                            }
                                                        }

                                                        if ($hasErrors) {
                                                            $statusClass = 'danger';
                                                            $statusIcon = 'times';
                                                            $statusText = 'Issues Found';
                                                        } elseif ($hasWarnings) {
                                                            $statusClass = 'warning';
                                                            $statusIcon = 'warning';
                                                            $statusText = 'Warnings';
                                                        } else {
                                                            $statusClass = 'success';
                                                            $statusIcon = 'check';
                                                            $statusText = 'Healthy';
                                                        }
                                                        ?>
                                                        <span class="badge bg-<?php echo $statusClass; ?> d-flex align-items-center gap-1">
                                                            <span class="icon-<?php echo $statusIcon; ?>" aria-hidden="true"></span>
                                                            <?php echo $statusText; ?>
                                                        </span>
                                                        <br><small class="text-muted"><?php echo count($checks); ?> checks</small>
                                                    </td>
                                                    <td>
                                                        <input type="number" 
                                                               class="form-control form-control-sm priority-input" 
                                                               name="providers[<?php echo $providerKey; ?>][priority]" 
                                                               value="<?php echo $priority; ?>" 
                                                               min="1" 
                                                               max="100">
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="form-check form-switch d-flex justify-content-center">
                                                            <input class="form-check-input" 
                                                                   type="checkbox" 
                                                                   name="providers[<?php echo $providerKey; ?>][enabled]" 
                                                                   id="provider_<?php echo $providerKey; ?>_enabled"
                                                                   value="1" 
                                                                   <?php echo $isEnabled ? 'checked' : ''; ?>
                                                                   aria-label="Enable <?php echo htmlspecialchars($providerName); ?>">
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group" role="group" aria-label="Provider actions">
                                                            <button type="button" 
                                                                    class="btn btn-sm btn-outline-secondary" 
                                                                    onclick="healthChecker.testProvider('<?php echo $providerKey; ?>')"
                                                                    title="Test Provider">
                                                                <span class="icon-play" aria-hidden="true"></span>
                                                                <span class="visually-hidden">Test</span>
                                                            </button>
                                                            <?php if (!empty($metadata['version'])) : ?>
                                                                <button type="button" 
                                                                        class="btn btn-sm btn-outline-info" 
                                                                        onclick="healthChecker.showProviderInfo('<?php echo $providerKey; ?>')"
                                                                        title="Provider Information">
                                                                    <span class="icon-info" aria-hidden="true"></span>
                                                                    <span class="visually-hidden">Info</span>
                                                                </button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php $firstCategory = false; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Dashboard Panel Layout -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title mb-0 h5 d-flex align-items-center gap-2">
                    <span class="icon-grid" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_PANEL_LAYOUT'); ?>
                </h3>
            </div>
            <div class="card-body">
                <fieldset>
                    <legend class="visually-hidden"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_PANEL_SIZES'); ?></legend>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="health_score_size" class="form-label">
                                <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_HEALTH_SCORE_PANEL'); ?>
                            </label>
                            <select id="health_score_size" name="layout[health_score_size]" class="form-select" aria-describedby="health-score-help">
                                <option value="col-lg-4"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SIZE_THIRD'); ?></option>
                                <option value="col-lg-6" selected><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SIZE_HALF'); ?></option>
                                <option value="col-lg-12"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SIZE_FULL'); ?></option>
                            </select>
                            <div id="health-score-help" class="form-text"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_HEALTH_SCORE_HELP'); ?></div>
                        </div>
                        <div class="col-md-4">
                            <label for="system_overview_size" class="form-label">
                                <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SYSTEM_OVERVIEW_PANEL'); ?>
                            </label>
                            <select id="system_overview_size" name="layout[system_overview_size]" class="form-select" aria-describedby="system-overview-help">
                                <option value="col-lg-4" selected><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SIZE_THIRD'); ?></option>
                                <option value="col-lg-6"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SIZE_HALF'); ?></option>
                                <option value="col-lg-12"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SIZE_FULL'); ?></option>
                            </select>
                            <div id="system-overview-help" class="form-text"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SYSTEM_OVERVIEW_HELP'); ?></div>
                        </div>
                        <div class="col-md-4">
                            <label for="critical_issues_size" class="form-label">
                                <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_CRITICAL_ISSUES_PANEL'); ?>
                            </label>
                            <select id="critical_issues_size" name="layout[critical_issues_size]" class="form-select" aria-describedby="critical-issues-help">
                                <option value="col-lg-4" selected><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SIZE_THIRD'); ?></option>
                                <option value="col-lg-6"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SIZE_HALF'); ?></option>
                                <option value="col-lg-12"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_SIZE_FULL'); ?></option>
                            </select>
                            <div id="critical-issues-help" class="form-text"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_CRITICAL_ISSUES_HELP'); ?></div>
                        </div>
                    </div>
                </fieldset>
            </div>
        </div>

        <!-- Global Settings -->
        <div class="card mb-4">
            <div class="card-header">
                <h3 class="card-title mb-0 h5 d-flex align-items-center gap-2">
                    <span class="icon-options" aria-hidden="true"></span>
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_GLOBAL_SETTINGS'); ?>
                </h3>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="cache_duration" class="form-label">
                            <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_CACHE_DURATION'); ?>
                        </label>
                        <select class="form-select" id="cache_duration" name="global[cache_duration]">
                            <option value="0"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_NO_CACHE'); ?></option>
                            <option value="300" selected><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_5_MINUTES'); ?></option>
                            <option value="900"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_15_MINUTES'); ?></option>
                            <option value="3600"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_1_HOUR'); ?></option>
                        </select>
                        <div class="form-text"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_CACHE_DURATION_DESC'); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label for="auto_scan" class="form-label">
                            <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_AUTO_SCAN'); ?>
                        </label>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" id="auto_scan" name="global[auto_scan]" value="1">
                            <label class="form-check-label" for="auto_scan">
                                <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_ENABLE_AUTO_SCAN'); ?>
                            </label>
                        </div>
                        <div class="form-text"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_AUTO_SCAN_DESC'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden Fields -->
        <input type="hidden" name="task" value="" />
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
</div>

<script>
// Accessible provider management JavaScript
if (typeof healthChecker === 'undefined') {
    window.healthChecker = {
        // Screen reader announcement helper
        announceToScreenReader: function(message, category = null) {
            const announcer = category ? 
                document.getElementById('provider-announcements-' + category) :
                document.getElementById('system-message-container');
            
            if (announcer) {
                announcer.textContent = message;
                // Clear after announcement
                setTimeout(() => {
                    announcer.textContent = '';
                }, 3000);
            }
        },
        
        // Test individual provider via AJAX
        testProvider: function(providerKey) {
            const token = document.querySelector('input[name="_token"]').value;
            
            this.announceToScreenReader('Testing provider, please wait...');
            
            fetch('index.php?option=com_admin&task=healthcheckconfig.testProvider', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `provider=${providerKey}&${token}=1`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const message = `Provider "${data.provider}" tested successfully! Category: ${data.category}, Checks: ${data.checksCount}`;
                    this.announceToScreenReader(message);
                    alert(message);
                } else {
                    const message = 'Test failed: ' + data.message;
                    this.announceToScreenReader(message);
                    alert(message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                const message = 'Test failed: Network error';
                this.announceToScreenReader(message);
                alert(message);
            });
        },
        
        // Move provider up or down (keyboard accessible)
        moveProvider: function(providerKey, direction) {
            const row = document.querySelector(`tr[data-provider="${providerKey}"]`);
            if (!row) return;
            
            const tbody = row.closest('tbody');
            const category = tbody.getAttribute('data-category');
            const providerName = row.getAttribute('data-provider-name');
            
            let targetRow = null;
            if (direction === 'up') {
                targetRow = row.previousElementSibling;
                if (targetRow) {
                    tbody.insertBefore(row, targetRow);
                }
            } else if (direction === 'down') {
                targetRow = row.nextElementSibling;
                if (targetRow) {
                    tbody.insertBefore(targetRow, row);
                }
            }
            
            if (targetRow) {
                // Announce movement to screen readers
                this.announceToScreenReader(
                    `${providerName} moved ${direction}`,
                    category
                );
                
                // Update provider order
                this.updateProviderOrder(category);
                
                // Maintain focus on the moved row
                row.focus();
                
                // Update move button states
                this.updateMoveButtonStates(tbody);
            }
        },
        
        // Update provider order via AJAX
        updateProviderOrder: function(category) {
            const tbody = document.querySelector(`.sortable-providers[data-category="${category}"]`);
            if (!tbody) return;
            
            const providerOrder = Array.from(tbody.querySelectorAll('.provider-row')).map(row => {
                return row.getAttribute('data-provider');
            });
            
            const token = document.querySelector('input[name="_token"]').value;
            
            fetch('index.php?option=com_admin&task=healthcheckconfig.reorderProviders', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `providerOrder=${JSON.stringify(providerOrder)}&${token}=1`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update priority inputs to reflect new order
                    providerOrder.forEach((providerKey, index) => {
                        const priorityInput = document.querySelector(`input[name="providers[${providerKey}][priority]"]`);
                        if (priorityInput) {
                            priorityInput.value = (index + 1) * 10;
                        }
                    });
                }
            })
            .catch(error => {
                console.error('Error updating order:', error);
            });
        },
        
        // Update move button states (disable first/last appropriately)
        updateMoveButtonStates: function(tbody) {
            const rows = tbody.querySelectorAll('.provider-row');
            
            rows.forEach((row, index) => {
                const upBtn = row.querySelector('.move-up-btn');
                const downBtn = row.querySelector('.move-down-btn');
                
                if (upBtn) {
                    upBtn.disabled = index === 0;
                    upBtn.setAttribute('aria-disabled', index === 0);
                }
                
                if (downBtn) {
                    downBtn.disabled = index === rows.length - 1;
                    downBtn.setAttribute('aria-disabled', index === rows.length - 1);
                }
            });
        },
        
        // Show provider information
        showProviderInfo: function(providerKey) {
            // Basic implementation - could be enhanced with modal
            const row = document.querySelector(`tr[data-provider="${providerKey}"]`);
            if (row) {
                const providerName = row.getAttribute('data-provider-name');
                this.announceToScreenReader(`Showing information for ${providerName}`);
                alert(`Provider information for: ${providerName}`);
            }
        }
    };
}

// Initialize accessibility features
document.addEventListener('DOMContentLoaded', function() {
    // Initialize move button states
    document.querySelectorAll('.sortable-providers').forEach(tbody => {
        healthChecker.updateMoveButtonStates(tbody);
    });
    
    // Keyboard navigation for provider rows
    document.querySelectorAll('.provider-row').forEach(row => {
        row.addEventListener('keydown', function(e) {
            switch(e.key) {
                case 'ArrowUp':
                    e.preventDefault();
                    const prevRow = this.previousElementSibling;
                    if (prevRow && prevRow.classList.contains('provider-row')) {
                        prevRow.focus();
                    }
                    break;
                    
                case 'ArrowDown':
                    e.preventDefault();
                    const nextRow = this.nextElementSibling;
                    if (nextRow && nextRow.classList.contains('provider-row')) {
                        nextRow.focus();
                    }
                    break;
                    
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    // Toggle enabled checkbox
                    const checkbox = this.querySelector('.form-check-input');
                    if (checkbox) {
                        checkbox.checked = !checkbox.checked;
                        checkbox.dispatchEvent(new Event('change'));
                    }
                    break;
            }
        });
    });
    
    // Form validation with accessibility feedback
    const form = document.getElementById('healthcheck-config-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const priorityInputs = form.querySelectorAll('.priority-input');
            let isValid = true;
            let firstInvalidInput = null;
            
            priorityInputs.forEach(input => {
                const value = parseInt(input.value);
                if (isNaN(value) || value < 1 || value > 100) {
                    isValid = false;
                    input.classList.add('is-invalid');
                    input.setAttribute('aria-invalid', 'true');
                    
                    if (!firstInvalidInput) {
                        firstInvalidInput = input;
                    }
                } else {
                    input.classList.remove('is-invalid');
                    input.setAttribute('aria-invalid', 'false');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                
                // Focus first invalid input
                if (firstInvalidInput) {
                    firstInvalidInput.focus();
                }
                
                // Announce error to screen readers
                healthChecker.announceToScreenReader(
                    'Form validation failed. Please ensure all priority values are between 1 and 100.'
                );
                
                alert('Please ensure all priority values are between 1 and 100.');
            }
        });
        
        // Real-time validation feedback
        form.addEventListener('input', function(e) {
            if (e.target.classList.contains('priority-input')) {
                const value = parseInt(e.target.value);
                if (isNaN(value) || value < 1 || value > 100) {
                    e.target.classList.add('is-invalid');
                    e.target.setAttribute('aria-invalid', 'true');
                } else {
                    e.target.classList.remove('is-invalid');
                    e.target.setAttribute('aria-invalid', 'false');
                }
            }
        });
    }
    
    // Initialize native HTML5 drag and drop for mouse users
    document.querySelectorAll('.provider-row').forEach(function(row) {
        // Make rows draggable
        row.draggable = true;
        
        row.addEventListener('dragstart', function(e) {
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', this.outerHTML);
            e.dataTransfer.setData('text/plain', this.getAttribute('data-provider'));
            
            // Announce drag start
            const providerName = this.getAttribute('data-provider-name');
            const tbody = this.closest('tbody');
            const category = tbody.getAttribute('data-category');
            healthChecker.announceToScreenReader(
                `Started dragging ${providerName}`,
                category
            );
        });
        
        row.addEventListener('dragend', function(e) {
            this.classList.remove('dragging');
        });
        
        row.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        });
        
        row.addEventListener('drop', function(e) {
            e.preventDefault();
            const draggedProvider = e.dataTransfer.getData('text/plain');
            const draggedRow = document.querySelector(`tr[data-provider="${draggedProvider}"]`);
            
            if (draggedRow && draggedRow !== this) {
                const tbody = this.closest('tbody');
                const category = tbody.getAttribute('data-category');
                
                // Insert the dragged row before this row
                tbody.insertBefore(draggedRow, this);
                
                // Announce completion
                const providerName = draggedRow.getAttribute('data-provider-name');
                healthChecker.announceToScreenReader(
                    `${providerName} position updated`,
                    category
                );
                
                // Update order and button states
                healthChecker.updateProviderOrder(category);
                healthChecker.updateMoveButtonStates(tbody);
            }
        });
    });
});
</script>
