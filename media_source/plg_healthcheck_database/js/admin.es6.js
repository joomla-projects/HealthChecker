/**
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Health Check - Database: Check now, the automatic background refreshes, the schema tile text, and the
 * Details dialog with its Ignore / Reopen actions.
 *
 * Table and column names come from the database. The details HTML is escaped by the server and
 * sanitised again here; anything added here goes through textContent or escape().
 */
import JoomlaDialog from 'joomla.dialog';

const OPTIONS = Joomla.getOptions('plg_healthcheck_database') || {};

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

const failed = (error) => {
  Joomla.renderMessages({ error: [escape(sprintf(text('PLG_HEALTHCHECK_DATABASE_REQUEST_FAILED'), error.message))] });
};

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

const post = (task, fields = {}) => request(task, { method: 'POST', body: new URLSearchParams(fields) });

const setIconText = (icon, value) => {
  const target = icon.querySelector('.j-links-link') || icon;
  target.textContent = value;
};

// A batch that could not check a table: stop, the next batch would only fail on the same table.
const stopOnError = (result) => {
  if (result.error) {
    throw new Error(text('PLG_HEALTHCHECK_DATABASE_CHECKNOW_ERROR'));
  }
};

// A busy batch means another request is counting a table of the pass: wait and ask again, this many times.
const BUSY_WAIT_MS = 2000;
const BUSY_TRIES = 30;

const wait = (ms) => new Promise((resolve) => {
  setTimeout(resolve, ms);
});

const stalled = () => new Error(text('PLG_HEALTHCHECK_DATABASE_CHECKNOW_STALLED'));

/**
 * Run zero-date batches until the pass is complete. Every batch checks at least one table, so a
 * batch that makes no progress means another request restarted the pass: stop rather than loop.
 * A busy batch did nothing because another request (another tab, the automatic refresh) is running
 * the pass, even for the first, restarting, request: continuing that pass is fine, so wait and ask
 * again, up to BUSY_TRIES times in a row.
 */
const zeroDatesPass = async (restart, onProgress) => {
  let result = await post('zerodates', { restart: restart ? '1' : '0' });
  let busyTries = 0;
  onProgress(result);
  stopOnError(result);

  while (!result.complete) {
    const previous = result;

    if (result.busy) {
      busyTries += 1;

      if (busyTries > BUSY_TRIES) {
        throw stalled();
      }

      await wait(BUSY_WAIT_MS);
    } else {
      busyTries = 0;
    }

    result = await post('zerodates', { restart: '0' });
    onProgress(result);
    stopOnError(result);

    if (!result.complete && !result.busy && !previous.busy
      && result.checked <= previous.checked && result.total === previous.total) {
      throw stalled();
    }
  }

  return result;
};

let checking = false;

// The automatic refreshes, if running: Check now waits for them before restarting the pass.
let autoRefresh = null;

// Check now: refresh the unused tables, then a full zero-date pass from the first table, then reload.
async function checkNow() {
  const icon = this instanceof Element ? this : null;

  if (checking || !icon) {
    return;
  }

  checking = true;
  icon.classList.add('disabled');
  icon.setAttribute('aria-disabled', 'true');
  icon.setAttribute('aria-live', 'polite');
  setIconText(icon, text('PLG_HEALTHCHECK_DATABASE_CHECKNOW_STARTING'));

  try {
    if (autoRefresh) {
      await autoRefresh;
    }

    await post('unused');
    await zeroDatesPass(true, ({ checked = 0, total = 0 }) => {
      if (total > 0) {
        setIconText(icon, sprintf(text('PLG_HEALTHCHECK_DATABASE_CHECKNOW_PROGRESS'), checked, total));
      }
    });
  } catch (error) {
    setIconText(icon, sprintf(text('PLG_HEALTHCHECK_DATABASE_CHECKNOW_FAILED'), error.message));
    icon.classList.remove('disabled');
    icon.removeAttribute('aria-disabled');
    checking = false;

    return;
  }

  setIconText(icon, text('PLG_HEALTHCHECK_DATABASE_CHECKNOW_DONE'));
  window.location.reload();
}

// Show the elements of a row or group header that belong to its state, hide the others.
const applyState = (scope, state) => {
  scope.dataset.hcDbState = state;
  scope.querySelectorAll('[data-hc-db-show]').forEach((el) => {
    el.hidden = !el.dataset.hcDbShow.split(' ').includes(state);
  });
};

// After a successful Ignore / Reopen, switch the badge and buttons in place.
const updateAfter = (action) => {
  const ignore = action.dataset.hcDbAction === 'ignore';
  const group = action.closest('[data-hc-db-group]');
  const header = group ? group.querySelector('[data-hc-db-header]') : null;

  if (action.dataset.hcDbKind === 'prefix') {
    applyState(header, ignore ? 'ignored' : 'open');
    group.querySelectorAll(`tr[data-hc-db-state="${ignore ? 'open' : 'prefix'}"]`).forEach((row) => {
      applyState(row, ignore ? 'prefix' : 'open');
    });

    return;
  }

  const prefixIgnored = header && header.dataset.hcDbState === 'ignored';
  let state = 'ignored';

  if (!ignore) {
    state = prefixIgnored ? 'prefix' : 'open';
  }

  applyState(action.closest('[data-hc-db-state]'), state);
};

const openDetails = async (trigger, kind, key, all) => {
  if (trigger.dataset.hcDbBusy) {
    return;
  }

  trigger.dataset.hcDbBusy = '1';

  let html;

  try {
    ({ html = '' } = await request('details', {}, `&kind=${encodeURIComponent(kind)}&key=${encodeURIComponent(key)}${all ? '&all=1' : ''}`));
  } catch (error) {
    failed(error);

    return;
  } finally {
    delete trigger.dataset.hcDbBusy;
  }

  let changed = false;

  // The server escapes every value; the markup is sanitised again here with table markup allowed,
  // and passed as an element so the dialog's own sanitiser (no table markup) leaves it alone.
  const template = document.createElement('template');
  template.innerHTML = Joomla.sanitizeHtml(html, DETAILS_ALLOWLIST);
  const content = document.createElement('div');
  content.appendChild(template.content);

  // Ignore / Reopen failures are shown inside the dialog; a page message would sit behind the modal.
  const showError = (error) => {
    let box = content.querySelector('.plg-hc-db-error');

    if (!box) {
      box = document.createElement('div');
      box.className = 'alert alert-danger plg-hc-db-error';
      box.setAttribute('role', 'alert');
      content.prepend(box);
    }

    box.textContent = sprintf(text('PLG_HEALTHCHECK_DATABASE_REQUEST_FAILED'), error.message);
  };

  content.addEventListener('click', async (event) => {
    const action = event.target.closest('button[data-hc-db-action]');

    if (!action) {
      return;
    }

    const scope = action.closest('[data-hc-db-state]');
    const buttons = scope.querySelectorAll('button');
    const body = { kind: action.dataset.hcDbKind, name: action.dataset.hcDbName };

    buttons.forEach((b) => { b.disabled = true; });

    try {
      const { ok } = await post(action.dataset.hcDbAction, body);

      if (ok) {
        updateAfter(action);
        changed = true;
      } else {
        showError(new Error(text('PLG_HEALTHCHECK_DATABASE_ACTION_REFUSED')));
      }
    } catch (error) {
      showError(error);
    } finally {
      buttons.forEach((b) => { b.disabled = false; });
    }
  });

  // JoomlaDialog restores inline content to its original parent on destroy(); a detached element
  // has none and throws, so park the content in a hidden holder first.
  const holder = document.createElement('div');
  holder.hidden = true;
  holder.appendChild(content);
  document.body.appendChild(holder);

  const dialog = new JoomlaDialog({
    popupType: 'inline',
    textHeader: escape(text(kind === 'zerodates' ? 'PLG_HEALTHCHECK_DATABASE_ZERODATES_NAME' : 'PLG_HEALTHCHECK_DATABASE_UNUSED_NAME')),
    textClose: text('JCLOSE'),
    popupContent: content,
    width: '90vw',
    height: 'fit-content',
  });

  // Counts are worked out on the server: reload the dashboard when something changed.
  dialog.addEventListener('joomla-dialog:close', () => {
    if (changed) {
      window.location.reload();
    } else {
      dialog.destroy();
      holder.remove();
    }
  });

  dialog.show();
};

document.addEventListener('click', (event) => {
  const button = event.target.closest('.plg-hc-db-details');

  if (button) {
    event.preventDefault();
    openDetails(button, button.dataset.hcDbKind || '', button.dataset.hcDbKey || '', false);
  }
});

// Core's async script fills only the amount and status of the schema tile; the text comes from the
// same request. `label` is plain text and goes in with textContent.
const fillSchemaText = async () => {
  const tile = document.getElementById('plg_healthcheck_database_schema');

  if (!tile) {
    return;
  }

  try {
    const { label } = await request('schema');

    if (typeof label === 'string' && label !== '') {
      setIconText(tile, label);
    }
  } catch (error) {
    // The tile keeps its static text; core's script reports the failure on the amount.
  }
};

// Background refreshes: the unused-table list when the stored one is stale (the page shows the stale list),
// and one zero-date batch when the stored pass is unfinished or stale (the server starts a new pass when
// the last one is complete and stale). No reload: the next dashboard visit shows the result.
const refreshInBackground = async () => {
  if (checking || (!OPTIONS.unusedDue && !OPTIONS.zeroDatesDue)) {
    return;
  }

  // Check now reports errors; the automatic refresh stays silent.
  const quiet = () => {};

  autoRefresh = (async () => {
    if (OPTIONS.unusedDue) {
      await post('unused').catch(quiet);
    }

    if (OPTIONS.zeroDatesDue) {
      await post('zerodates', { restart: '0' }).catch(quiet);
    }
  })();

  await autoRefresh;
  autoRefresh = null;
};

const registerActions = () => {
  Joomla.registerHealthCheckAction('plgHealthcheckDatabaseCheckNow', checkNow);
  Joomla.registerHealthCheckAction('plgHealthcheckDatabaseZeroDates', function zeroDates() {
    openDetails(this, 'zerodates', '', true);
  });
  Joomla.registerHealthCheckAction('plgHealthcheckDatabaseUnused', function unused() {
    openDetails(this, 'unused', '', true);
  });
};

const start = () => {
  if (typeof Joomla.registerHealthCheckAction === 'function') {
    registerActions();
  }

  fillSchemaText();
  refreshInBackground();
};

// This module runs once the document is parsed (readyState "interactive"), but possibly before the dashboard's
// action registry script (mod_healthcheck onclick), which defines Joomla.registerHealthCheckAction. Start now
// when the registry is already there, otherwise on DOMContentLoaded, which fires after every deferred and module
// script. A late load (DOMContentLoaded already passed) starts at once.
if (typeof Joomla.registerHealthCheckAction === 'function' || document.readyState === 'complete') {
  start();
} else {
  document.addEventListener('DOMContentLoaded', start, { once: true });
}
