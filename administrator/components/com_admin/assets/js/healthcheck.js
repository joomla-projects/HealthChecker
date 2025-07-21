/**
 * @package     Joomla.Administrator
 * @subpackage  com_admin
 *
 * @copyright   (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * JavaScript for the Health Checker dashboard
 * Merges CD's original functionality with Joomla/Atum patterns
 *
 * @since  5.4
 */

// Extension details data (from CD's original work)
const extensionDetails = {
    1: {
        name: 'JCE Editor',
        author: 'JCE Team',
        type: 'Component',
        current_version: '2.9.55',
        compatible_version: '3.1.2',
        status: 'needs_update',
        risk_level: 'medium',
        description: 'JCE is a WYSIWYG editor for Joomla. The current version has known compatibility issues with Joomla 5.x.',
        issues: [
            'Legacy jQuery dependencies',
            'Deprecated API calls',
            'CSS conflicts with new admin template'
        ],
        recommendations: [
            'Update to version 3.1.2 before upgrading Joomla',
            'Test editor functionality after update',
            'Consider alternative editors if issues persist'
        ],
        download_url: 'https://www.joomlacontenteditor.net/download',
        documentation: 'https://www.joomlacontenteditor.net/documentation'
    },
    2: {
        name: 'Akeeba Backup',
        author: 'Akeeba Ltd',
        type: 'Component',
        current_version: '9.8.1',
        compatible_version: '9.8.1',
        status: 'compatible',
        risk_level: 'low',
        description: 'Akeeba Backup is fully compatible with the target Joomla version.',
        issues: [],
        recommendations: [
            'No action required',
            'Continue using current version'
        ],
        download_url: 'https://www.akeeba.com/',
        documentation: 'https://www.akeeba.com/documentation.html'
    },
    3: {
        name: 'Custom Template',
        author: 'Custom Developer',
        type: 'Template',
        current_version: '1.0.0',
        compatible_version: 'Unknown',
        status: 'incompatible',
        risk_level: 'high',
        description: 'Custom template requires manual review for Joomla 5.x compatibility.',
        issues: [
            'Uses deprecated template structure',
            'Missing required template files',
            'Incompatible override system'
        ],
        recommendations: [
            'Contact original developer for update',
            'Consider migrating to Cassiopeia-based template',
            'Audit all template overrides'
        ],
        download_url: null,
        documentation: null
    }
};

(function() {
    'use strict';

    /**
     * Initialize the health checker when page loads
     */
    document.addEventListener('DOMContentLoaded', function() {
        initializeHealthChecker();
        setupEventListeners();
        updateHealthScore();
    });

    /**
     * Initialize the health checker interface
     */
    function initializeHealthChecker() {
        console.log('Joomla Health Checker initialized');
        
        // Setup expandable sections (CD's functionality)
        setupExpandableSections();
        
        // Setup filter tabs
        setupFilterTabs();
        
        // Initialize health score animation (CD's functionality)
        animateHealthScore();
        
        // Set up periodic updates (demo purposes)
        setInterval(updateHealthScore, 30000);
    }

    /**
     * Set up event listeners for interactive elements
     */
    function setupEventListeners() {
        // Modal close when clicking outside
        const modal = document.getElementById('extensionDetailsModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeExtensionDetails();
                }
            });
        }
        
        // Keyboard events
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeExtensionDetails();
            }
        });
    }

    /**
     * Setup expandable sections functionality (CD's feature)
     */
    function setupExpandableSections() {
        document.querySelectorAll('.healthchecker-expandable-header').forEach(header => {
            header.addEventListener('click', function() {
                const expandable = this.parentElement;
                const content = expandable.querySelector('.healthchecker-expandable-content');
                const icon = this.querySelector('.icon-chevron-down');
                
                if (content.style.display === 'none') {
                    content.style.display = 'block';
                    expandable.classList.add('active');
                    if (icon) icon.classList.add('rotate-180');
                } else {
                    content.style.display = 'none';
                    expandable.classList.remove('active');
                    if (icon) icon.classList.remove('rotate-180');
                }
            });
        });
    }

    /**
     * Setup filter tabs functionality
     */
    function setupFilterTabs() {
        document.querySelectorAll('[data-filter]').forEach(button => {
            button.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');
                
                // Update active button using Bootstrap nav-tabs
                document.querySelectorAll('[data-filter]').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                // Filter table rows
                filterExtensionRows(filter);
            });
        });
    }

    /**
     * Filter extension table rows based on status
     */
    function filterExtensionRows(filter) {
        const rows = document.querySelectorAll('.healthchecker-extension-row');
        
        rows.forEach(row => {
            const status = row.getAttribute('data-status');
            
            if (filter === 'all' || status === filter) {
                row.style.display = '';
                row.style.opacity = '1';
            } else {
                row.style.opacity = '0';
                setTimeout(() => {
                    if (row.style.opacity === '0') {
                        row.style.display = 'none';
                    }
                }, 200);
            }
        });
        
        // Update table visibility message if no results
        updateTableVisibility();
    }

    /**
     * Update table visibility message
     */
    function updateTableVisibility() {
        const hiddenRows = document.querySelectorAll('.healthchecker-extension-row[style*="display: none"]').length;
        const totalRows = document.querySelectorAll('.healthchecker-extension-row').length;
        
        if (hiddenRows === totalRows) {
            showNoResultsMessage();
        } else {
            hideNoResultsMessage();
        }
    }

    /**
     * Show no results message
     */
    function showNoResultsMessage() {
        const existingMessage = document.getElementById('no-results-message');
        if (existingMessage) return;
        
        const table = document.querySelector('.healthchecker-data-table');
        if (!table) return;
        
        const message = document.createElement('div');
        message.id = 'no-results-message';
        message.className = 'alert alert-info';
        message.style.margin = '1rem 0';
        message.innerHTML = '<strong>No results found:</strong> Try adjusting your filter selection.';
        
        table.parentNode.insertBefore(message, table.nextSibling);
    }

    /**
     * Hide no results message
     */
    function hideNoResultsMessage() {
        const message = document.getElementById('no-results-message');
        if (message) {
            message.remove();
        }
    }

    /**
     * Animate the health score circle (CD's functionality adapted for Atum)
     */
    function animateHealthScore() {
        const circle = document.querySelector('.healthchecker-score-circle');
        const scoreValue = document.querySelector('.healthchecker-score-value');
        
        if (!circle || !scoreValue) return;
        
        const score = parseInt(scoreValue.textContent);
        let currentScore = 0;
        
        const animation = setInterval(() => {
            currentScore += 2;
            if (currentScore >= score) {
                currentScore = score;
                clearInterval(animation);
            }
            
            scoreValue.textContent = currentScore;
            updateScoreCircle(currentScore);
        }, 50);
    }

    /**
     * Update the health score circle based on score (CD's functionality)
     */
    function updateScoreCircle(score) {
        const circle = document.querySelector('.healthchecker-score-circle');
        if (!circle) return;
        
        // Update CSS custom property for the conic gradient
        circle.style.setProperty('--score', score);
        
        // Update status based on score
        updateStatusText(score);
    }

    /**
     * Update status text based on score (CD's functionality)
     */
    function updateStatusText(score) {
        const statusElement = document.querySelector('.healthchecker-score-status');
        if (!statusElement) return;
        
        let statusText = 'Good - Ready for upgrade';
        let statusClass = 'bg-success';
        
        if (score < 60) {
            statusText = 'Critical - Do not upgrade';
            statusClass = 'bg-danger';
        } else if (score < 80) {
            statusText = 'Warning - Review issues first';
            statusClass = 'bg-warning';
        }
        
        statusElement.textContent = statusText;
        statusElement.className = `badge ${statusClass} healthchecker-score-status`;
    }

    /**
     * Update health score (demo function)
     */
    function updateHealthScore() {
        const scoreElement = document.querySelector('.healthchecker-score-value');
        if (!scoreElement) return;
        
        let currentScore = parseInt(scoreElement.textContent);
        
        // Simulate small fluctuations for demo
        const newScore = Math.max(75, Math.min(95, currentScore + (Math.random() - 0.5) * 4));
        
        if (Math.abs(newScore - currentScore) > 1) {
            scoreElement.textContent = Math.round(newScore);
            updateScoreCircle(Math.round(newScore));
        }
    }

    /**
     * View extension details in modal (CD's functionality with Bootstrap 5 modal)
     */
    function viewExtensionDetails(extensionId) {
        const extension = extensionDetails[extensionId];
        
        if (!extension) {
            showNotification('Extension details not found', 'error');
            return;
        }
        
        // Update modal content
        const modalTitle = document.getElementById('extensionDetailsModalLabel');
        const modalContent = document.getElementById('modalExtensionContent');
        
        modalTitle.textContent = extension.name + ' Details';
        modalContent.innerHTML = generateExtensionDetailsHTML(extension);
        
        // Show modal using Bootstrap 5
        const modal = new bootstrap.Modal(document.getElementById('extensionDetailsModal'));
        modal.show();
    }

    /**
     * Generate HTML for extension details modal (CD's functionality)
     */
    function generateExtensionDetailsHTML(extension) {
        const statusBadgeClass = getStatusClass(extension.status);
        const riskClass = getRiskClass(extension.risk_level);
        
        let html = `
            <div class="row">
                <div class="col-md-6">
                    <h6>Extension Information</h6>
                    <table class="table table-sm">
                        <tr><th>Name:</th><td>${extension.name}</td></tr>
                        <tr><th>Author:</th><td>${extension.author}</td></tr>
                        <tr><th>Type:</th><td>${extension.type}</td></tr>
                        <tr><th>Current Version:</th><td>${extension.current_version}</td></tr>
                        <tr><th>Compatible Version:</th><td>${extension.compatible_version || 'Unknown'}</td></tr>
                        <tr><th>Status:</th><td><span class="badge bg-${statusBadgeClass}">${getStatusText(extension.status)}</span></td></tr>
                        <tr><th>Risk Level:</th><td><span class="badge bg-outline-${riskClass}">${getRiskText(extension.risk_level)}</span></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6>Description</h6>
                    <p>${extension.description}</p>
                </div>
            </div>
        `;
        
        if (extension.issues.length > 0) {
            html += `
                <div class="mt-3">
                    <h6 class="text-warning">Issues Found</h6>
                    <ul class="list-unstyled">
                        ${extension.issues.map(issue => `
                            <li class="mb-1">
                                <span class="icon-warning text-warning me-2"></span>
                                ${issue}
                            </li>
                        `).join('')}
                    </ul>
                </div>
            `;
        }
        
        html += `
            <div class="mt-3">
                <h6 class="text-primary">Recommendations</h6>
                <ul class="list-unstyled">
                    ${extension.recommendations.map(rec => `
                        <li class="mb-1">
                            <span class="icon-check text-success me-2"></span>
                            ${rec}
                        </li>
                    `).join('')}
                </ul>
            </div>
        `;
        
        if (extension.download_url || extension.documentation) {
            html += `
                <div class="mt-3">
                    <h6>Links</h6>
                    <div class="d-flex gap-2">
                        ${extension.download_url ? `<a href="${extension.download_url}" class="btn btn-primary btn-sm" target="_blank">Download Update</a>` : ''}
                        ${extension.documentation ? `<a href="${extension.documentation}" class="btn btn-secondary btn-sm" target="_blank">View Documentation</a>` : ''}
                    </div>
                </div>
            `;
        }
        
        return html;
    }

    /**
     * Helper functions for status and risk classes
     */
    function getStatusClass(status) {
        const classes = {
            'compatible': 'success',
            'needs_update': 'warning',
            'incompatible': 'danger'
        };
        return classes[status] || 'secondary';
    }

    function getRiskClass(risk) {
        const classes = {
            'low': 'success',
            'medium': 'warning',
            'high': 'danger'
        };
        return classes[risk] || 'secondary';
    }

    function getStatusText(status) {
        const texts = {
            'compatible': 'Compatible',
            'needs_update': 'Update Required',
            'incompatible': 'Incompatible'
        };
        return texts[status] || 'Unknown';
    }

    function getRiskText(risk) {
        const texts = {
            'low': 'Low',
            'medium': 'Medium',
            'high': 'High'
        };
        return texts[risk] || 'Unknown';
    }

    /**
     * Show notification using Joomla's message system
     */
    function showNotification(message, type = 'info') {
        // Use Joomla's built-in message system
        if (typeof Joomla !== 'undefined' && Joomla.renderMessages) {
            const messages = {};
            messages[type] = [message];
            Joomla.renderMessages(messages);
        } else {
            // Fallback to console for development
            console.log(`[${type.toUpperCase()}] ${message}`);
        }
    }

    /**
     * Global functions for button actions (CD's functionality adapted)
     */
    window.healthChecker = {
        viewExtensionDetails: viewExtensionDetails,
        
        exportReport: function() {
            showNotification('Generating report...', 'info');
            setTimeout(() => {
                showNotification('Report exported successfully!', 'success');
            }, 2000);
        },

        runFullScan: function() {
            showNotification('Starting full health check scan...', 'info');
            let progress = 0;
            const scanInterval = setInterval(() => {
                progress += 20;
                showNotification(`Scanning... ${progress}%`, 'info');
                
                if (progress >= 100) {
                    clearInterval(scanInterval);
                    showNotification('Health check scan completed!', 'success');
                    // In real implementation, this would reload page data
                }
            }, 500);
        },

        startUpgradeProcess: function() {
            if (confirm('Are you sure you want to start the Joomla upgrade process? This may take several minutes.')) {
                showNotification('Upgrade process initiated...', 'warning');
                // In real implementation, this would redirect to upgrade interface
            }
        },

        generateDetailedReport: function() {
            showNotification('Generating detailed report...', 'info');
            setTimeout(() => {
                showNotification('Detailed report generated!', 'success');
            }, 2000);
        },

        rerunHealthCheck: function() {
            if (confirm('Re-run all health checks? This will refresh all current data.')) {
                showNotification('Re-running health checks...', 'info');
                setTimeout(() => {
                    showNotification('Health checks completed!', 'success');
                    // In real implementation, this would reload the page
                    // window.location.reload();
                }, 3000);
            }
        },

        refreshHealthCheckData: function(checkType) {
            showNotification(`Refreshing ${checkType} data...`, 'info');
            setTimeout(() => {
                showNotification(`${checkType} data refreshed`, 'success');
            }, 1500);
        }
    };

})();