document.addEventListener('DOMContentLoaded', function () {
  var containers = document.querySelectorAll('.wlt-bookshop-featured-container');
  console.log('[Bookshop checker] Running on', containers.length, 'containers');

  containers.forEach(function (container) {
    var nid = container.getAttribute('data-nid');
    var isbn = container.getAttribute('data-isbn');
    var token = container.getAttribute('data-report-token');
    var iframes = container.querySelectorAll('iframe');
    if (iframes.length === 0) {
      console.log('[Bookshop checker] No iframes in container nid=' + nid + ' isbn=' + isbn);
      return;
    }

    var anyOk = false;
    var pending = iframes.length;

    iframes.forEach(function (iframe) {
      var src = iframe.getAttribute('src');
      if (!src) { pending--; return; }

      // HEAD request is sufficient; treat CORS failures as broken.
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
            if (nid && isbn && token && typeof Drupal !== 'undefined' && Drupal.url) {
              fetch(Drupal.url('wlt-bookshop/report-bad-isbn/' + nid) + '?value=' + encodeURIComponent(isbn) + '&token=' + encodeURIComponent(token), {
                method: 'POST', credentials: 'same-origin'
              });
            }
          }
        })
        .catch(function (err) {
          // CORS/network error: hide container and report.
          var parent = iframe.parentElement;
          if (parent) parent.style.display = 'none';
          console.error('[Bookshop checker] 🕳️ CORS/network error', src, err, '— hiding this block');
          if (nid && isbn && token && typeof Drupal !== 'undefined' && Drupal.url) {
            fetch(Drupal.url('wlt-bookshop/report-bad-isbn/' + nid) + '?value=' + encodeURIComponent(isbn) + '&token=' + encodeURIComponent(token), {
              method: 'POST', credentials: 'same-origin'
            });
          }
        })
        .finally(function () {
          pending--;
          if (pending === 0) {
            if (!anyOk) {
              // All failed → hide entire container.
              container.style.display = 'none';
            }
            console.log('[Bookshop checker] Completed for nid=' + nid + ' isbn=' + isbn + (anyOk ? ' (some OK)' : ' (all failed)'));
          }
        });
    });
  });
});
