/**
 * @copyright  (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Frontend error collector. Standalone and loaded first (low asset weight), so it sees errors
 * from every later script. Its options travel on its own script tag (data-options), because the
 * script options element is rendered after it. Nothing here may ever throw into the page.
 */
((window, document) => {
  const tag = document.currentScript;
  let options = null;

  try {
    options = JSON.parse(tag.dataset.options);
  } catch (e) {
    options = window.Joomla && window.Joomla.getOptions ? window.Joomla.getOptions('plg_system_frontenderrors') : null;
  }

  if (!options) {
    return;
  }

  const scan = /[?&]hc_scan=1(&|$)/.test(window.location.search);
  const nav = window.navigator;

  if (!scan && (nav.webdriver || /bot|crawl|spider|slurp/i.test(nav.userAgent) || Math.random() * 100 >= options.sample)) {
    return;
  }

  const MAX = 10;
  const LIMIT = 8000;
  const known = {};
  let seen = {};
  let reports = [];
  let timer = 0;
  let total = 0;

  // In a scan the list is never flushed, so the page can read it as it grows.
  if (scan) {
    window.JoomlaFrontendErrors = { reports, ready: false };
  }

  const str = (value) => {
    try {
      return String(value);
    } catch (e) {
      return typeof value;
    }
  };

  const guard = (fn) => (...args) => {
    try {
      fn(...args);
    } catch (e) {
      // Never throw into the page.
    }
  };

  const send = (list) => {
    const body = JSON.stringify({ page: window.location.pathname, reports: list });
    const blob = new Blob([body], { type: 'application/json' });

    if (blob.size <= LIMIT) {
      nav.sendBeacon(options.endpoint, blob);
    } else if (list.length > 1) {
      send(list.slice(0, list.length >> 1));
      send(list.slice(list.length >> 1));
    } else if (list[0].stack) {
      send([{ ...list[0], stack: '' }]);
    }
  };

  const flush = guard(() => {
    const list = reports;
    reports = [];
    seen = {};
    timer = 0;

    if (list.length) {
      send(list);
    }
  });

  const add = (type, message, file, line, col, stack) => {
    const msg = str(message).slice(0, 500);
    const f = file && str(file).slice(0, 255);
    const key = [type, msg, f, line, col].join('|');

    if (seen[key]) {
      seen[key].count += 1;
      return;
    }

    // A repeat after a flush is sent again as a fresh report; only new keys count toward the cap.
    if (!known[key]) {
      if (total >= MAX) {
        return;
      }

      known[key] = 1;
      total += 1;
    }

    const report = {
      type, message: msg, file: f, line, col, stack: stack && str(stack).slice(0, 2000), count: 1,
    };
    seen[key] = report;
    reports.push(report);

    if (!scan && !timer) {
      timer = window.setTimeout(flush, 5000);
    }
  };

  window.addEventListener('error', guard((e) => {
    const t = e.target;

    if (e instanceof ErrorEvent || !t || t === window) {
      add('error', e.message, e.filename, e.lineno, e.colno, e.error && e.error.stack);
    } else if (/^(SCRIPT|LINK|IMG)$/.test(t.tagName)) {
      add('resource', `Failed to load ${t.tagName.toLowerCase()}`, t.src || t.href);
    } else {
      const src = t.currentSrc || t.src || t.href;

      if (src) {
        add('resource', `Failed to load ${String(t.tagName).toLowerCase()}`, src);
      }
    }
  }), true);

  window.addEventListener('unhandledrejection', guard((e) => {
    const r = e.reason;
    add('rejection', r && r.message ? r.message : str(r), '', 0, 0, r && r.stack);
  }));

  document.addEventListener('securitypolicyviolation', guard((e) => {
    add('csp', e.violatedDirective, e.blockedURI, e.lineNumber, e.columnNumber);
  }));

  if (options.console) {
    const original = window.console.error;
    window.console.error = (...args) => {
      try {
        original.apply(window.console, args);
      } finally {
        guard(() => add('console', args.map(str).join(' ')))();
      }
    };
  }

  if (scan) {
    window.addEventListener('load', () => {
      window.JoomlaFrontendErrors.ready = true;
    });
  } else {
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        flush();
      }
    });
    window.addEventListener('pagehide', flush);
  }
})(window, document);
