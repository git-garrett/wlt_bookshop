(function () {
  if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
    return;
  }

  var TIMEOUT_MS = 6000;

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    }
    else {
      fn();
    }
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

  function interpretOutcome(outcome) {
    if (outcome.kind === 'value') {
      if (!outcome.value.ok) {
        return false;
      }
      var resp = outcome.value.resp;
      if (resp && typeof resp.status === 'number') {
        if (resp.type === 'opaque') {
          return false;
        }
        return resp.status >= 200 && resp.status < 300;
      }
      return false;
    }
    if (outcome.kind === 'timeout' || outcome.kind === 'error') {
      return null;
    }
    return false;
  }

  function removeIfEmpty(grid) {
    if (!grid) {
      return;
    }
    if (!grid.querySelector('[data-wlt-bookshop-card]')) {
      grid.remove();
    }
  }

  function checkIframe(iframe) {
    var url = iframe ? iframe.getAttribute('src') : '';
    if (!url) {
      return Promise.resolve(false);
    }

    return withTimeout(tryFetch(url, 'HEAD'), TIMEOUT_MS).then(function (headOutcome) {
      var verdict = interpretOutcome(headOutcome);
      if (typeof verdict === 'boolean') {
        return verdict;
      }
      return withTimeout(tryFetch(url, 'GET'), TIMEOUT_MS).then(function (getOutcome) {
        var secondVerdict = interpretOutcome(getOutcome);
        return typeof secondVerdict === 'boolean' ? secondVerdict : false;
      });
    });
  }

  function processGrid(grid) {
    var cards = Array.prototype.slice.call(grid.querySelectorAll('[data-wlt-bookshop-card]'));
    if (!cards.length) {
      grid.remove();
      return;
    }
    cards.forEach(function (card) {
      var iframe = card.querySelector('iframe[data-wlt-bookshop-iframe]');
      if (!iframe) {
        card.remove();
        removeIfEmpty(grid);
        return;
      }
      checkIframe(iframe).then(function (ok) {
        if (!ok) {
          card.remove();
          removeIfEmpty(grid);
        }
      });
    });
  }

  ready(function () {
    var grids = Array.prototype.slice.call(document.querySelectorAll('[data-wlt-bookshop-grid]'));
    grids.forEach(processGrid);
  });
})();
