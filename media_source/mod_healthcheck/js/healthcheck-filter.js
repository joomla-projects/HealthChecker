/**
 * @package     Joomla.Administrator
 * @subpackage  mod_healthcheck
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    
    // Get all filter buttons
    const filterButtons = document.querySelectorAll('.healthcheck-filters [data-filter]');
    
    if (filterButtons.length === 0) {
        return;
    }
    
    // Get all health check items that can be filtered
    const healthCheckItems = document.querySelectorAll('.quickicon-single[data-filter-status], .quickicon-group[data-filter-status]');
    
    // Function to filter items
    function filterItems(filterType) {
        healthCheckItems.forEach(function(item) {
            const itemStatus = item.getAttribute('data-filter-status');
            
            if (filterType === 'all') {
                // Show all items
                item.style.display = '';
                item.classList.remove('d-none');
            } else if (filterType === itemStatus) {
                // Show items matching the filter (healthy, warning, or critical)
                item.style.display = '';
                item.classList.remove('d-none');
            } else {
                // Hide items not matching the filter
                item.style.display = 'none';
                item.classList.add('d-none');
            }
        });
    }
    
    // Add click event listeners to filter buttons
    filterButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Remove active class from all buttons
            filterButtons.forEach(function(btn) {
                btn.classList.remove('active');
            });
            
            // Add active class to clicked button
            this.classList.add('active');
            
            // Get the filter type
            const filterType = this.getAttribute('data-filter');
            
            // Filter the items
            filterItems(filterType);
        });
    });
    
    // Initialize with 'all' filter
    filterItems('all');
});
