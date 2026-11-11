/**
 * @package     Joomla.Administrator
 * @subpackage  mod_healthcheck
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

((Joomla, document) => {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const FILTER_ALIASES = {
      alert: 'warning',
      danger: 'critical',
      error: 'critical',
      info: 'healthy',
      ok: 'healthy',
      success: 'healthy',
      warn: 'warning',
    };

    const filterBars = document.querySelectorAll('.healthcheck-filters');

    if (filterBars.length === 0) {
      return;
    }

    function normalizeStatus(value) {
      const status = String(value || '').trim().toLowerCase();

      if (status === '') {
        return 'healthy';
      }

      return FILTER_ALIASES[status] || status;
    }

    function getItemStatus(item) {
      if (item.hasAttribute('data-healthcheck-status')) {
        return normalizeStatus(item.getAttribute('data-healthcheck-status'));
      }

      return 'healthy';
    }

    function setItemVisibility(item, shouldShow) {
      item.classList.toggle('d-none', !shouldShow);
      item.hidden = !shouldShow;
      item.style.display = shouldShow ? '' : 'none';
      item.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
    }

    function initFilterBar(filterBar) {
      const filterButtons = filterBar.querySelectorAll('[data-filter]');
      const moduleRoot = filterBar.parentElement || document;
      const healthCheckItems = moduleRoot.querySelectorAll('[data-healthcheck-status]');

      const sections = moduleRoot.querySelectorAll('.healthcheck-section');

      function setSectionOpen(section, open) {
        const panel = section.querySelector('.accordion-collapse');
        const toggle = section.querySelector('.accordion-button');

        if (!panel || !toggle) {
          return;
        }

        panel.classList.toggle('show', open);
        toggle.classList.toggle('collapsed', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      }

      function updateSections(normalizedFilter) {
        sections.forEach((section) => {
          const items = [...section.querySelectorAll('[data-healthcheck-status]')];
          const hasVisible = items.some((item) => !item.hidden);
          const show = normalizedFilter === 'all' || hasVisible;

          section.hidden = !show;
          section.classList.toggle('d-none', !show);
          setSectionOpen(section, normalizedFilter !== 'all' && hasVisible);
        });
      }

      if (filterButtons.length === 0 || healthCheckItems.length === 0) {
        return;
      }

      function setActiveButton(activeButton) {
        filterButtons.forEach((button) => {
          button.classList.toggle('active', button === activeButton);
          button.setAttribute('aria-pressed', button === activeButton ? 'true' : 'false');
        });
      }

      function filterItems(filterType) {
        const normalizedFilter = normalizeStatus(filterType);

        healthCheckItems.forEach((item) => {
          const itemStatus = getItemStatus(item);
          const shouldShow = normalizedFilter === 'all' || itemStatus === normalizedFilter;

          setItemVisibility(item, shouldShow);
        });

        updateSections(normalizedFilter);
      }

      const ICONS = {
        critical: 'icon-times text-danger',
        warning: 'icon-warning text-warning',
        healthy: 'icon-check text-success',
      };

      // Recount one section from its items and refresh its header (badges, worst icon, hidden word).
      function refreshSectionHeader(section) {
        const counts = { critical: 0, warning: 0, healthy: 0 };

        section.querySelectorAll('[data-healthcheck-status]').forEach((item) => {
          const status = getItemStatus(item);
          counts[status in counts ? status : 'healthy'] += 1;
        });

        const header = section.querySelector('.accordion-button');
        const labels = header ? header.querySelector('[data-healthcheck-label-critical]') : null;

        if (!header || !labels) {
          return;
        }

        ['critical', 'warning', 'healthy'].forEach((status) => {
          const badge = header.querySelector(`[data-healthcheck-count="${status}"]`);

          if (badge) {
            badge.textContent = (labels.getAttribute(`data-healthcheck-label-${status}`) || '%d').replace('%d', counts[status]);
            badge.classList.toggle('d-none', counts[status] === 0);
          }
        });

        const worst = counts.critical ? 'critical' : (counts.warning ? 'warning' : 'healthy');
        const icon = header.querySelector('[data-healthcheck-worst-icon]');
        const word = header.querySelector('[data-healthcheck-worst-text]');

        if (icon) {
          icon.className = `${ICONS[worst]} me-2`;
        }

        if (word) {
          word.textContent = labels.getAttribute(`data-healthcheck-word-${worst}`) || word.textContent;
        }
      }

      moduleRoot.addEventListener('healthcheck:status', (event) => {
        const section = event.target.closest('.healthcheck-section');

        if (section) {
          refreshSectionHeader(section);
        }

        const active = filterBar.querySelector('[data-filter].active');
        filterItems(active ? active.getAttribute('data-filter') : 'all');
      });

      filterBar.addEventListener('click', (event) => {
        const button = event.target.closest('button');

        if (!button || !filterBar.contains(button)) {
          return;
        }

        const filterType = button.getAttribute('data-filter');

        if (filterType) {
          event.preventDefault();
          setActiveButton(button);
          filterItems(filterType);

          return;
        }

        if (button.querySelector('.icon-refresh')) {
          event.preventDefault();

          const defaultButton = filterBar.querySelector('[data-filter="all"]') || filterButtons[0];

          if (defaultButton) {
            setActiveButton(defaultButton);
          }

          filterItems('all');
        }
      });

      const initialButton = filterBar.querySelector('[data-filter].active') || filterBar.querySelector('[data-filter="all"]') || filterButtons[0];

      if (initialButton) {
        setActiveButton(initialButton);
        filterItems(initialButton.getAttribute('data-filter'));
      } else {
        filterItems('all');
      }
    }

    filterBars.forEach(initFilterBar);
  });
})(window.Joomla, document);
