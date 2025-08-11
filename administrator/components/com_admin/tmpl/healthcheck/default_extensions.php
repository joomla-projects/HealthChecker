<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_admin
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \Joomla\Component\Admin\Administrator\View\Healthcheck\HtmlView $this */

/** @var \Joomla\Component\Admin\Administrator\Model\HealthcheckModel $model */
$model = $this->getModel();

// Helper functions
$getStatusClass = function($status) {
    return match($status) {
        'compatible' => 'success',
        'needs_update' => 'warning',
        'incompatible' => 'danger',
        default => 'secondary'
    };
};

$getRiskClass = function($risk) {
    return match($risk) {
        'low' => 'success',
        'medium' => 'warning', 
        'high' => 'danger',
        default => 'secondary'
    };
};

?>

<div class="table-responsive" role="region" aria-labelledby="extensions-heading">
    <h3 id="extensions-heading" class="sr-only">Extensions compatibility table</h3>
    <table class="table table-striped table-hover healthchecker-data-table" 
           id="extension-table" 
           aria-label="Table showing extension compatibility status and details">
        <caption class="sr-only">
            Table contains <?php echo count($this->extensionData); ?> extensions with compatibility information
        </caption>
        <thead class="table-dark">
            <tr>
                <th scope="col" aria-sort="none" tabindex="0">
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_EXTENSION'); ?>
                    <span class="sr-only">Click to sort</span>
                </th>
                <th scope="col"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_TYPE'); ?></th>
                <th scope="col"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_CURRENT_VERSION'); ?></th>
                <th scope="col"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_COMPATIBLE_VERSION'); ?></th>
                <th scope="col" aria-sort="none" tabindex="0">
                    <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_STATUS'); ?>
                    <span class="sr-only">Click to sort</span>
                </th>
                <th scope="col"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_RISK_LEVEL'); ?></th>
                <th scope="col"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_ACTIONS'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($this->extensionData as $extension): ?>
                <tr class="healthchecker-extension-row" data-status="<?php echo $extension['status']; ?>">
                    <td>
                        <div>
                            <strong><?php echo $this->escape($extension['name']); ?></strong>
                            <?php if (!empty($extension['author'])): ?>
                                <br><small class="text-muted"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_BY'); ?> <?php echo $this->escape($extension['author']); ?></small>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-secondary"><?php echo ucfirst($extension['type']); ?></span>
                    </td>
                    <td><?php echo $this->escape($extension['current_version']); ?></td>
                    <td><?php echo $this->escape($extension['compatible_version'] ?? Text::_('COM_ADMIN_HEALTH_CHECKER_UNKNOWN')); ?></td>
                    <td>
                        <span class="badge bg-<?php echo $getStatusClass($extension['status']); ?> d-flex align-items-center gap-1" 
                              style="width: fit-content;"
                              role="status"
                              aria-label="Status: <?php echo $model->getStatusText($extension['status']); ?>">
                            <?php 
                            $icons = [
                                'compatible' => 'check',
                                'needs_update' => 'warning', 
                                'incompatible' => 'times'
                            ];
                            $icon = $icons[$extension['status']] ?? 'question';
                            ?>
                            <span class="icon-<?php echo $icon; ?>" aria-hidden="true"></span>
                            <?php echo $model->getStatusText($extension['status']); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge bg-outline-<?php echo $getRiskClass($extension['risk_level']); ?> healthchecker-risk-level">
                            <?php echo $model->getRiskText($extension['risk_level']); ?>
                        </span>
                    </td>
                    <td>
                        <button type="button" class="btn btn-outline-primary btn-sm" 
                                onclick="healthChecker.viewExtensionDetails(<?php echo $extension['id']; ?>)"
                                aria-label="View details for <?php echo $this->escape($extension['name']); ?>">
                            <span class="icon-eye" aria-hidden="true"></span>
                            <?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_VIEW_DETAILS'); ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Extension Details Modal using Bootstrap 5 modal -->
<div class="modal fade" id="extensionDetailsModal" tabindex="-1" aria-labelledby="extensionDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="extensionDetailsModalLabel"><?php echo Text::_('COM_ADMIN_HEALTH_CHECKER_EXTENSION_DETAILS'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo Text::_('JCLOSE'); ?>"></button>
            </div>
            <div class="modal-body" id="modalExtensionContent">
                <!-- Content loaded dynamically via JavaScript -->
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden"><?php echo Text::_('JLOADING'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>