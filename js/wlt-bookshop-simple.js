(function () {
  if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
    return;
  }

  var TIMEOUT_MS = 6000;

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  function isoNow() {
    return new Date().toISOString();
  }

  function appendLog(grid, level, message) {
    var container = grid.querySelector('[data-wlt-bookshop-debug-log]');
    if (!container) {
      container = document.createElement('ul');
      container.setAttribute('data-wlt-bookshop-debug-log', '1');
      container.className = 'wlt-bookshop-debug__log';
      grid.appendChild(container);
    }
    var line = document.createElement('li');
    line.setAttribute('data-level', level || 'info');
    var stamp = new Date().toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    line.textContent = "[" + stamp + "] " + message;
    container.appendChild(line);
    container.scrollTop = container.scrollHeight;
  }

  function withTimeout(promise, ms) {
    return new Promise(function (resolve) {
      var settled = false;
      var timer = setTimeout(function () {
        if (!settled) {
          settled = true;
          resolve({ kind: 'timeout' });
        }
      }, ms);
      promise.then(function (value) {
        if (!settled) {
          settled = true;
          clearTimeout(timer);
          resolve({ kind: 'value', value: value });
        }
      }).catch(function (error) {
        if (!settled) {
          settled = true;
          clearTimeout(timer);
          resolve({ kind: 'error', error: error });
        }
      });
    });
  }

  function tryFetch(url, method) {
    return fetch(url, {
      method: method,
      mode: 'cors',
      redirect: 'follow',
      credentials: 'omit'
    }).then(function (resp) {
      return { ok: true, resp: resp };
    }).catch(function (err) {
      return { ok: false, err: err };
    });
  }

  function normalizeOutcome(outcome, method) {
    var info = {
      method: method,
      verdict: 'unknown',
      status: null,
      statusText: '',
      error: null,
      retry: false,
      allow: false
    };

    if (!outcome) {
      info.verdict = 'no-outcome';
      info.retry = method === 'HEAD';
      return info;
    }

    if (outcome.kind === 'value') {
      var payload = outcome.value;
      if (!payload || payload.ok !== true) {
        info.verdict = 'fetch-error';
        info.error = payload && payload.err ? (payload.err.message || String(payload.err)) : 'fetch rejected';
        info.retry = method === 'HEAD';
        return info;
      }

      var resp = payload.resp;
      if (resp) {
        info.status = typeof resp.status === 'number' ? resp.status : null;
        info.statusText = resp.statusText || '';
        if (resp.type === 'opaque') {
          info.verdict = 'opaque';
          info.allow = true;
          return info;
        }
        if (info.status !== null) {
          if (info.status >= 200 && info.status < 300) {
            info.verdict = 'ok';
            info.allow = true;
          } else {
            info.verdict = 'bad-status';
            info.allow = false;
          }
          return info;
        }
      }

      info.verdict = 'unknown';
      info.retry = method === 'HEAD';
      return info;
    }

    if (outcome.kind === 'timeout') {
      info.verdict = 'timeout';
      info.retry = method === 'HEAD';
      return info;
    }

    if (outcome.kind === 'error') {
      info.verdict = 'exception';
      info.error = outcome.error ? (outcome.error.message || String(outcome.error)) : '';
      info.retry = method === 'HEAD';
      return info;
    }

    info.verdict = 'unknown';
    info.retry = method === 'HEAD';
    return info;
  }

  function finalizeResult(result) {
    if (!result) {
      return { method: 'HEAD', verdict: 'no-result', allow: false };
    }
    if (result.verdict === 'ok' || result.verdict === 'opaque') {
      result.allow = true;
    } else if (typeof result.allow !== 'boolean') {
      result.allow = false;
    }
    return result;
  }

  function checkIframe(iframe) {
    var url = iframe ? iframe.getAttribute('src') : '';
    if (!url) {
      return Promise.resolve(finalizeResult({
        method: 'HEAD',
        verdict: 'missing-url',
        allow: false
      }));
    }

    function perform(method) {
      return withTimeout(tryFetch(url, method), TIMEOUT_MS).then(function (outcome) {
        return normalizeOutcome(outcome, method);
      });
    }

    return perform('GET').then(function (getResult) {
      var finalGet = finalizeResult(getResult);
      finalGet.method = 'GET';
      return finalGet;
    }).catch(function (error) {
      return finalizeResult({
        method: 'GET',
        verdict: 'exception',
        error: error ? (error.message || String(error)) : 'unknown',
        allow: false
      });
    });
  }

  function describeResult(result) {
    if (!result) {
      return 'no result returned';
    }
    var parts = [];
    parts.push((result.method || 'unknown method').toUpperCase());
    if (typeof result.status === 'number') {
      parts.push('status ' + result.status);
    }
    if (result.verdict === 'opaque') {
      parts.push('opaque response (treated as success)');
    } else {
      parts.push('verdict ' + result.verdict);
    }
    if (result.error) {
      parts.push('error: ' + result.error);
    }
    return parts.join(', ');
  }

  function updateCardCount(grid) {
    var cardsContainer = grid.querySelector('[data-wlt-bookshop-cards]');
    var remaining = 0;
    if (cardsContainer) {
      remaining = cardsContainer.querySelectorAll('[data-wlt-bookshop-card]').length;
    }
    grid.setAttribute('data-wlt-bookshop-card-count', String(remaining));
    if (remaining === 0) {
      grid.setAttribute('data-wlt-bookshop-empty', '1');
    } else {
      grid.setAttribute('data-wlt-bookshop-empty', '0');
    }
    return remaining;
  }

  function addSummaryData(grid, kept, removed) {
    grid.setAttribute('data-wlt-bookshop-last-run', isoNow());
    grid.setAttribute('data-wlt-bookshop-kept', String(kept));
    grid.setAttribute('data-wlt-bookshop-removed', String(removed));
  }

  function processGrid(grid) {
    var permission = grid.getAttribute('data-wlt-bookshop-permission') || 'unknown';
    var uid = grid.getAttribute('data-wlt-bookshop-uid') || '0';
    appendLog(grid, 'info', 'Grid detected (uid ' + uid + ', permission ' + permission + ').');

    var cardsContainer = grid.querySelector('[data-wlt-bookshop-cards]');
    if (!cardsContainer) {
      appendLog(grid, 'error', 'No widget container found in markup.');
      updateCardCount(grid);
      addSummaryData(grid, 0, 0);
      return;
    }

    var cards = Array.prototype.slice.call(cardsContainer.querySelectorAll('[data-wlt-bookshop-card]'));
    appendLog(grid, 'info', 'Initial widget count: ' + cards.length + '.');

    if (permission !== 'allowed') {
      grid.classList.add('wlt-bookshop-permission-denied');
      appendLog(grid, 'warn', 'Permission denied by formatter configuration. Skipping widget processing.');
      updateCardCount(grid);
      addSummaryData(grid, 0, 0);
      return;
    }

    if (!cards.length) {
      appendLog(grid, 'warn', 'No widgets available to evaluate.');
      updateCardCount(grid);
      addSummaryData(grid, 0, 0);
      return;
    }

    var pending = cards.length;
    var kept = 0;
    var removed = 0;

    function finalize() {
      var remaining = updateCardCount(grid);
      addSummaryData(grid, kept, removed);
      appendLog(grid, 'info', 'Evaluation complete: kept ' + kept + ', removed ' + removed + ', remaining in DOM ' + remaining + '.');
      if (remaining === 0) {
        appendLog(grid, 'warn', 'All widgets removed; grid marked empty.');
      }
    }

    cards.forEach(function (card, index) {
      var ordinal = index + 1;
      var ean = card.getAttribute('data-wlt-bookshop-ean') || 'unknown';
      var iframe = card.querySelector('iframe[data-wlt-bookshop-iframe]');
      if (!iframe) {
        removed += 1;
        card.remove();
        appendLog(grid, 'error', 'Widget #' + ordinal + ' (EAN ' + ean + ') missing iframe element; removed from DOM.');
        pending -= 1;
        if (pending === 0) {
          finalize();
        }
        return;
      }

      appendLog(grid, 'info', 'Checking widget #' + ordinal + ' (EAN ' + ean + ').');

      checkIframe(iframe).then(function (result) {
        appendLog(grid, 'info', 'Widget #' + ordinal + ' GET result: ' + describeResult(result) + '.');

        if (result.allow) {
          kept += 1;
          appendLog(grid, 'info', 'Widget #' + ordinal + ' kept (' + describeResult(result) + ').');
        } else {
          removed += 1;
          card.remove();
          appendLog(grid, 'warn', 'Widget #' + ordinal + ' removed (' + describeResult(result) + ').');
        }
      }).catch(function (error) {
        removed += 1;
        card.remove();
        appendLog(grid, 'error', 'Widget #' + ordinal + ' threw exception: ' + (error && error.message ? error.message : String(error)));
      }).then(function () {
        pending -= 1;
        if (pending === 0) {
          finalize();
        }
      });
    });
  }

  ready(function () {
    var grids = Array.prototype.slice.call(document.querySelectorAll('[data-wlt-bookshop-grid]'));
    if (!grids.length) {
      return;
    }
    grids.forEach(function (grid) {
      processGrid(grid);
    });
  });
})();
