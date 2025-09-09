(function () {
  function reportSuppression(nid, isbn, token) {
    try {
      var base = (typeof Drupal !== 'undefined' && Drupal.url)
        ? Drupal.url('wlt-bookshop/report-bad-isbn/' + nid)
        : ('/wlt-bookshop/report-bad-isbn/' + nid);
      fetch(base + '?value=' + encodeURIComponent(isbn) + '&token=' + encodeURIComponent(token), {
        method: 'POST',
        credentials: 'same-origin'
      });
    } catch (e) {
      // ignore
    }
  }

  function checkContainer(container) {
    var nid = container.getAttribute('data-nid');
    var isbn = container.getAttribute('data-isbn');
    var token = container.getAttribute('data-report-token');

    var attempts = 0;
    var maxAttempts = 8; // ~8s total polling for iframes to appear

    function pollAndRun() {
      var iframes = container.querySelectorAll('iframe');
      if (iframes.length === 0) {
        attempts++;
        if (attempts < maxAttempts) {
          setTimeout(pollAndRun, 1000);
        } else {
          console.log('[Bookshop checker] No iframes found (nid=', nid, 'isbn=', isbn, ') after waiting');
        }
        return;
      }

      var anyOk = false;
      var pending = iframes.length;

      iframes.forEach(function (iframe) {
        var src = iframe.getAttribute('src');
        if (!src) { pending--; return; }

        // HEAD request; treat CORS/network error or non-2xx as failure.
        fetch(src, { method: 'HEAD', mode: 'cors', credentials: 'omit' })
          .then(function (resp) {
            if (resp && resp.ok) {
              anyOk = true;
              console.log('[Bookshop checker] ✅ OK', resp.status, src);
            } else {
              // Hide only this iframe's immediate wrapper block.
              var parent = iframe.parentElement;
              if (parent) parent.style.display = 'none';
              console.warn('[Bookshop checker] ❌ Non-2xx', (resp ? resp.status : '(no resp)'), src, '— hiding this block');
              reportSuppression(nid, isbn, token);
            }
          })
          .catch(function (err) {
            // CORS/network error: hide this block and report.
            var parent = iframe.parentElement;
            if (parent) parent.style.display = 'none';
            console.error('[Bookshop checker] 🕳️ CORS/network error', src, err, '— hiding this block');
            reportSuppression(nid, isbn, token);
          })
          .finally(function () {
            pending--;
            if (pending === 0) {
              if (!anyOk) {
                container.style.display = 'none';
              }
              console.log('[Bookshop checker] Completed nid=', nid, 'isbn=', isbn, anyOk ? '(some OK)' : '(all failed)');
            }
          });
      });
    }

    // Start polling after a short delay to give widgets time to inject.
    setTimeout(pollAndRun, 1000);
  }

  function runChecks() {
    var containers = document.querySelectorAll('.wlt-bookshop-featured-container');
    console.log('[Bookshop checker] Running on', containers.length, 'containers');
    containers.forEach(checkContainer);

    // Also watch for containers added later (AJAX or deferred rendering).
    var observer = new MutationObserver(function (records) {
      records.forEach(function (rec) {
        if (!rec.addedNodes) return;
        rec.addedNodes.forEach(function (node) {
          if (node.nodeType !== 1) return;
          if (node.classList && node.classList.contains('wlt-bookshop-featured-container')) {
            console.log('[Bookshop checker] New container detected; scheduling check');
            checkContainer(node);
          }
          var nested = node.querySelectorAll ? node.querySelectorAll('.wlt-bookshop-featured-container') : [];
          if (nested && nested.length) {
            nested.forEach(checkContainer);
          }
        });
      });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      // Inject a small floating button to manually re-run checks.
      injectRunner();
      // Delay initial run by 10s to allow widgets to inject iframes.
      setTimeout(runChecks, 10000);
    });
  } else {
    injectRunner();
    setTimeout(runChecks, 10000);
  }

  function injectRunner() {
    if (document.getElementById('bookshop-checker-run')) return;
    var btn = document.createElement('button');
    btn.id = 'bookshop-checker-run';
    btn.type = 'button';
    btn.textContent = 'Run Bookshop Checks';
    btn.style.position = 'fixed';
    btn.style.bottom = '16px';
    btn.style.right = '16px';
    btn.style.zIndex = '99999';
    btn.style.padding = '8px 12px';
    btn.style.background = '#1f2937';
    btn.style.color = '#e5e7eb';
    btn.style.border = '1px solid #374151';
    btn.style.borderRadius = '6px';
    btn.style.cursor = 'pointer';
    btn.addEventListener('click', function () {
      console.log('[Bookshop checker] Manual run triggered');
      runChecks();
    });
    document.body.appendChild(btn);
  }
})();
