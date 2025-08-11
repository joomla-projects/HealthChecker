/**
 * Health Checker Accessibility Enhancements
 * Provides keyboard navigation, focus management, and live region updates
 *
 * @package     Joomla.Administrator
 * @subpackage  com_admin  
 * @since       5.4
 */

document.addEventListener('DOMContentLoaded', function() {
    initializeAccessibility();
});

function initializeAccessibility() {
    setupKeyboardNavigation();
    setupLiveRegions();
    setupFocusManagement();
    setupExpandableAccessibility();
    setupTableAccessibility();
}

function setupKeyboardNavigation() {
    // Detect keyboard usage for enhanced focus indicators
    let isKeyboardUser = false;
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            isKeyboardUser = true;
            document.body.classList.add('healthchecker-keyboard-user');
        }
    });
    
    document.addEventListener('mousedown', function() {
        isKeyboardUser = false;
        document.body.classList.remove('healthchecker-keyboard-user');
    });

    // Enhanced tab navigation for filter tabs
    const filterTabs = document.querySelectorAll('[data-filter]');
    filterTabs.forEach((tab, index) => {
        tab.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowRight') {
                e.preventDefault();
                const nextTab = filterTabs[index + 1] || filterTabs[0];
                nextTab.focus();
                nextTab.click();
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                const prevTab = filterTabs[index - 1] || filterTabs[filterTabs.length - 1];
                prevTab.focus();
                prevTab.click();
            }
        });
    });
}

function setupLiveRegions() {
    window.announceToScreenReader = function(message, priority = 'polite') {
        const regionId = priority === 'assertive' ? 'healthchecker-error-announcements' : 'healthchecker-status-updates';
        const region = document.getElementById(regionId);
        if (region) {
            region.textContent = message;
            // Clear after announcement
            setTimeout(() => {
                region.textContent = '';
            }, 1000);
        }
    };
}

function setupFocusManagement() {
    // Modal focus trap
    const modal = document.getElementById('extensionDetailsModal');
    if (modal) {
        modal.addEventListener('shown.bs.modal', function() {
            const firstFocusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (firstFocusable) {
                firstFocusable.focus();
            }
        });
        
        modal.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const closeButton = modal.querySelector('.btn-close');
                if (closeButton) closeButton.click();
            }
        });
    }

    // Return focus after actions
    let lastFocusedElement = null;
    
    document.addEventListener('click', function(e) {
        if (e.target.matches('[onclick*="healthChecker"]')) {
            lastFocusedElement = e.target;
        }
    });
    
    // Enhanced focus for score circle
    const scoreCircle = document.querySelector('.healthchecker-score-circle');
    if (scoreCircle) {
        scoreCircle.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                const score = e.target.getAttribute('aria-valuenow');
                announceToScreenReader('Current health score: ' + score + ' out of 100');
            }
        });
    }
}

function setupExpandableAccessibility() {
    document.querySelectorAll('.healthchecker-expandable-header').forEach(header => {
        // Make expandable headers focusable and add ARIA attributes
        header.setAttribute('tabindex', '0');
        header.setAttribute('role', 'button');
        
        const content = header.nextElementSibling;
        if (content) {
            const expandableId = 'expandable-' + Math.random().toString(36).substr(2, 9);
            content.id = expandableId;
            header.setAttribute('aria-controls', expandableId);
            header.setAttribute('aria-expanded', 'false');
            
            header.addEventListener('click', toggleExpandable);
            header.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleExpandable.call(this);
                }
            });
            
            function toggleExpandable() {
                const isExpanded = this.getAttribute('aria-expanded') === 'true';
                this.setAttribute('aria-expanded', !isExpanded);
                
                const icon = this.querySelector('.icon-chevron-down, .icon-chevron-up');
                if (icon) {
                    icon.className = isExpanded ? 'icon-chevron-down' : 'icon-chevron-up';
                }
                
                if (window.announceToScreenReader) {
                    announceToScreenReader(
                        isExpanded ? 'Section collapsed' : 'Section expanded'
                    );
                }
            }
        }
    });
}

function setupTableAccessibility() {
    // Enhanced table sorting
    const sortableHeaders = document.querySelectorAll('th[aria-sort]');
    sortableHeaders.forEach(header => {
        header.addEventListener('click', function() {
            const currentSort = this.getAttribute('aria-sort');
            const newSort = currentSort === 'ascending' ? 'descending' : 'ascending';
            
            // Reset all other headers
            sortableHeaders.forEach(h => h.setAttribute('aria-sort', 'none'));
            
            // Set current header
            this.setAttribute('aria-sort', newSort);
            
            if (window.announceToScreenReader) {
                announceToScreenReader(
                    'Table sorted by ' + this.textContent.trim() + ' ' + newSort
                );
            }
        });
        
        header.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });
    });
}

// Extend existing healthChecker object with accessibility announcements if it exists
document.addEventListener('DOMContentLoaded', function() {
    // Wait a bit for other scripts to load
    setTimeout(() => {
        if (typeof window.healthChecker === 'object') {
            const originalRunFullScan = window.healthChecker.runFullScan;
            if (originalRunFullScan) {
                window.healthChecker.runFullScan = function() {
                    if (window.announceToScreenReader) {
                        announceToScreenReader('Starting health check scan');
                    }
                    originalRunFullScan.apply(this, arguments);
                };
            }
            
            const originalExportReport = window.healthChecker.exportReport;
            if (originalExportReport) {
                window.healthChecker.exportReport = function() {
                    if (window.announceToScreenReader) {
                        announceToScreenReader('Generating report');
                    }
                    originalExportReport.apply(this, arguments);
                };
            }
        }
    }, 500);
});