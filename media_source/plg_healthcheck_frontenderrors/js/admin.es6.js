/**
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Health Check - Frontend Errors: Scan now, the page details dialog and the Resolve / Ignore /
 * Reopen actions.
 *
 * Scan now opens each frontend page in a hidden same-origin frame with ?hc_scan=1, waits for the
 * collector to finish, reads window.JoomlaFrontendErrors and posts the reports to the server.
 *
 * Every value shown here comes from the public internet. The details HTML is escaped by the
 * server; anything added here goes through textContent or escape().
 */
import JoomlaDialog from 'joomla.dialog';

const OPTIONS = Joomla.getOptions('plg_healthcheck_frontenderrors') || {};
const SETTLE = 3000;
const TIMEOUT = 15000;
const POLL = 250;
const LIMIT = 8000;
const SKIPPED_KEY = 'plgHealthcheckFrontenderrorsSkipped';

// Joomla's default sanitiser list plus the table markup and the hidden attribute of the details layout.
const DETAILS_ALLOWLIST = {
  '*': ['class', 'dir', 'id', 'lang', 'role', 'hidden', /^aria-[\w-]*$/i, /^data-[\w-]*$/i],
  table: [],
  caption: [],
  thead: [],
  tbody: [],
  tr: [],
  th: ['scope'],
  td: [],
};

const text = (key) => Joomla.Text._(key);

// Replace %s, %d and %1$d / %2$s style placeholders in order.
const sprintf = (format, ...args) => {
  let next = 0;

  return format.replace(/%(?:(\d+)\$)?[sd]/g, (match, position) => {
    const index = position ? Number(position) - 1 : next;
    next += 1;

    return index < args.length ? String(args[index]) : match;
  });
};

const escape = (value) => {
  const div = document.createElement('div');
  div.textContent = String(value);

  return div.innerHTML;
};

const delay = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

const url = (task, query = '') => `${OPTIONS.ajax}&task=${task}&${OPTIONS.token}=1${query}`;

// com_ajax wraps the plugin results in {success, message, data: [result]}.
const request = async (task, init = {}, query = '') => {
  const response = await fetch(url(task, query), { credentials: 'same-origin', ...init });

  if (!response.ok) {
    throw new Error(String(response.status));
  }

  const json = await response.json();

  if (!json || !json.success) {
    throw new Error((json && json.message) || String(response.status));
  }

  return Array.isArray(json.data) ? (json.data[0] || {}) : (json.data || {});
};

const postJson = (task, body) => request(task, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(body),
});

// Split the reports of one page so that every request stays under the server's body limit.
const chunks = (page, reports) => {
  // Bytes, not characters: the server limit is in bytes and messages may be non-ASCII.
  const size = new Blob([JSON.stringify({ page, reports })]).size;

  if (size <= LIMIT) {
    return [reports];
  }

  if (reports.length > 1) {
    const half = reports.length >> 1;

    return [...chunks(page, reports.slice(0, half)), ...chunks(page, reports.slice(half))];
  }

  return reports[0].stack ? chunks(page, [{ ...reports[0], stack: '' }]) : [];
};

/**
 * Read the collector state of a frame.
 *
 * @returns {{status: string, page?: string, reports?: Array}}
 *   status ok, blocked (frame document not readable: framing refused or another origin) or
 *   unreadable (no finished collector on the page).
 */
const readFrame = (frame, loaded) => {
  let doc;

  try {
    doc = frame.contentDocument;
  } catch (e) {
    return { status: 'blocked' };
  }

  if (!doc) {
    return { status: 'blocked' };
  }

  try {
    const win = frame.contentWindow;

    const web = /^https?:$/.test(win.location.protocol);

    // Another origin, or a refused frame left on a blank or browser error document after load.
    if (web ? win.location.origin !== OPTIONS.origin : loaded) {
      return { status: 'blocked' };
    }

    const data = win.JoomlaFrontendErrors;

    if (!data || !data.ready || !Array.isArray(data.reports)) {
      return { status: 'unreadable' };
    }

    return {
      status: 'ok',
      page: win.location.pathname,
      reports: JSON.parse(JSON.stringify(data.reports)),
    };
  } catch (e) {
    return { status: 'blocked' };
  }
};

// Open one page in a hidden frame and collect its reports.
const visit = (page) => new Promise((resolve) => {
  const frame = document.createElement('iframe');
  const started = Date.now();
  let done = false;
  let loaded = false;
  let timer = 0;

  const finish = (result) => {
    if (done) {
      return;
    }

    done = true;
    clearTimeout(timer);
    frame.remove();
    resolve(result);
  };

  // After load and the settle delay, wait for the collector to be ready until the page timeout.
  const poll = () => {
    const result = readFrame(frame, loaded);

    if (result.status !== 'unreadable' || Date.now() - started >= TIMEOUT) {
      finish(result);
      return;
    }

    timer = setTimeout(poll, POLL);
  };

  timer = setTimeout(() => finish(readFrame(frame, loaded)), TIMEOUT);

  frame.addEventListener('load', () => {
    loaded = true;
    clearTimeout(timer);
    timer = setTimeout(poll, SETTLE);
  });

  frame.setAttribute('aria-hidden', 'true');
  frame.tabIndex = -1;
  frame.style.cssText = 'position:absolute;width:1024px;height:768px;left:-10000px';
  frame.src = `${OPTIONS.origin}${page}${page.includes('?') ? '&' : '?'}hc_scan=1`;
  document.body.appendChild(frame);
});

const setIconText = (icon, value) => {
  const target = icon.querySelector('.j-links-link') || icon;
  target.textContent = value;
};

let scanning = false;

async function scan(event) {
  const icon = (event && event.currentTarget instanceof Element ? event.currentTarget : this) || null;

  if (scanning || !icon) {
    return;
  }

  scanning = true;
  icon.classList.add('disabled');
  icon.setAttribute('aria-disabled', 'true');
  icon.setAttribute('aria-live', 'polite');
  setIconText(icon, text('PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_STARTING'));

  const skipped = [];

  try {
    const { pages = [] } = await request('pages');

    for (let i = 0; i < pages.length; i += 1) {
      const page = pages[i];

      setIconText(icon, sprintf(text('PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_PROGRESS'), i + 1, pages.length, page));

      const result = await visit(page);

      if (result.status === 'blocked' && i === 0) {
        setIconText(icon, text('PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_FRAMING_BLOCKED'));
        icon.classList.remove('disabled');
        icon.removeAttribute('aria-disabled');
        scanning = false;
        return;
      }

      if (result.status !== 'ok') {
        skipped.push(page);
      } else {
        for (const part of chunks(result.page, result.reports)) {
          if (part.length) {
            await postJson('scanresults', { page: result.page, reports: part });
          }
        }
      }
    }
  } catch (error) {
    setIconText(icon, sprintf(text('PLG_HEALTHCHECK_FRONTENDERRORS_REQUEST_FAILED'), error.message));
    icon.classList.remove('disabled');
    icon.removeAttribute('aria-disabled');
    scanning = false;
    return;
  }

  setIconText(icon, text('PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_DONE'));

  // The skipped pages are shown as a message after the reload, so they stay readable.
  if (skipped.length) {
    try {
      window.sessionStorage.setItem(SKIPPED_KEY, JSON.stringify(skipped));
    } catch (e) {
      // Storage unavailable: the list is only lost.
    }
  }

  setTimeout(() => window.location.reload(), 1500);
}

const showSkipped = () => {
  let skipped = null;

  try {
    skipped = JSON.parse(window.sessionStorage.getItem(SKIPPED_KEY) || 'null');
    window.sessionStorage.removeItem(SKIPPED_KEY);
  } catch (e) {
    return;
  }

  if (Array.isArray(skipped) && skipped.length) {
    Joomla.renderMessages({ warning: [escape(sprintf(text('PLG_HEALTHCHECK_FRONTENDERRORS_SCAN_SKIPPED'), skipped.join(', ')))] });
  }
};

// Show or hide the badges and buttons of a details row for its current state.
const applyState = (row, state) => {
  row.dataset.hcFeCurrent = String(state);
  row.querySelectorAll('[data-hc-fe-visible]').forEach((el) => {
    el.hidden = !el.dataset.hcFeVisible.split(' ').includes(String(state));
  });
};

const openDetails = async (button) => {
  const page = button.dataset.hcFePage || '';
  let changed = false;

  button.disabled = true;

  let html;

  try {
    ({ html = '' } = await request('details', {}, `&page=${encodeURIComponent(page)}`));
  } catch (error) {
    Joomla.renderMessages({ error: [escape(sprintf(text('PLG_HEALTHCHECK_FRONTENDERRORS_REQUEST_FAILED'), error.message))] });
    return;
  } finally {
    button.disabled = false;
  }

  // The server escapes every value; the markup is sanitised again here with table markup allowed,
  // and passed as an element so the dialog's own sanitiser (no table markup) leaves it alone.
  const template = document.createElement('template');
  template.innerHTML = Joomla.sanitizeHtml(html, DETAILS_ALLOWLIST);
  const content = document.createElement('div');
  content.appendChild(template.content);

  content.addEventListener('click', async (event) => {
    const action = event.target.closest('button[data-hc-fe-state]');

    if (!action) {
      return;
    }

    const row = action.closest('[data-hc-fe-row]');
    const state = action.dataset.hcFeState;
    const body = new URLSearchParams({ id: action.dataset.hcFeId, state });

    row.querySelectorAll('button').forEach((b) => { b.disabled = true; });

    try {
      const { ok } = await request('state', { method: 'POST', body });

      if (ok) {
        applyState(row, state);
        changed = true;
      }
    } catch (error) {
      Joomla.renderMessages({ error: [escape(sprintf(text('PLG_HEALTHCHECK_FRONTENDERRORS_REQUEST_FAILED'), error.message))] });
    } finally {
      row.querySelectorAll('button').forEach((b) => { b.disabled = false; });
    }
  });

  const dialog = new JoomlaDialog({
    popupType: 'inline',
    textHeader: escape(page),
    textClose: text('JCLOSE'),
    popupContent: content,
    width: '90vw',
    height: 'fit-content',
  });

  dialog.addEventListener('joomla-dialog:close', () => {
    if (changed) {
      window.location.reload();
    } else {
      dialog.destroy();
    }
  });

  dialog.show();
};

document.addEventListener('click', (event) => {
  const button = event.target.closest('.plg-hc-fe-details');

  if (button) {
    event.preventDefault();
    openDetails(button);
  }
});

// The dashboard action registry may load after this module; DOMContentLoaded follows both.
const register = () => {
  if (typeof Joomla.registerHealthCheckAction === 'function') {
    Joomla.registerHealthCheckAction('plgHealthcheckFrontenderrorsScan', scan);
  }
};

if (typeof Joomla.registerHealthCheckAction === 'function') {
  register();
} else {
  document.addEventListener('DOMContentLoaded', register);
}

showSkipped();
