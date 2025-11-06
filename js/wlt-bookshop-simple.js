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

    var cardsContainer = grid.querySelector('[data-wlt-bookshop-cards]');
    if (!cardsContainer) {
      updateCardCount(grid);
      addSummaryData(grid, 0, 0);
      return;
    }

    var cards = Array.prototype.slice.call(cardsContainer.querySelectorAll('[data-wlt-bookshop-card]'));

    if (permission !== 'allowed') {
      grid.classList.add('wlt-bookshop-permission-denied');
      updateCardCount(grid);
      addSummaryData(grid, 0, 0);
      return;
    }

    if (!cards.length) {
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
    }

    cards.forEach(function (card, index) {
      var ordinal = index + 1;
      var ean = card.getAttribute('data-wlt-bookshop-ean') || 'unknown';
      var iframe = card.querySelector('iframe[data-wlt-bookshop-iframe]');
      if (!iframe) {
        removed += 1;
        card.remove();
        pending -= 1;
        if (pending === 0) {
          finalize();
        }
        return;
      }

      checkIframe(iframe).then(function (result) {
        if (result.allow) {
          kept += 1;
        } else {
          removed += 1;
          card.remove();
        }
      }).catch(function (error) {
        removed += 1;
        card.remove();
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
