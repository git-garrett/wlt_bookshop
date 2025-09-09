(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.wltBookshopFeatured = {
    attach: function (context) {
      $('.wlt-bookshop-featured-container', context).once('wlt-bookshop-featured').each(function () {
        const $container = $(this);
        const nid = $container.data('nid');
        const isbn = $container.data('isbn');
        const token = $container.data('report-token');

        // After a delay to allow widget to inject iframes, verify they load.
        setTimeout(function () {
          const $iframes = $container.find('iframe');
          const sources = [];
          $iframes.each(function () {
            const src = this.getAttribute('src');
            if (src) { sources.push(src); }
          });

          // If we have sources, attempt a CORS fetch of each and require at least one 200.
          if (sources.length > 0) {
            const unique = Array.from(new Set(sources));
            const urlOkMap = {};
            const checks = unique.map(function (url) {
              return fetch(url, { method: 'GET', cache: 'no-store', redirect: 'follow' })
                .then(function (res) { urlOkMap[url] = !!res && res.ok; })
                .catch(function () { urlOkMap[url] = false; });
            });
            Promise.all(checks).then(function () {
              // Determine per-iframe result based on its src.
              let anyOk = false;
              $iframes.each(function () {
                const src = this.getAttribute('src');
                const ok = src && urlOkMap[src] === true;
                if (ok) { anyOk = true; }
              });

              // Hide and report each failing iframe block individually.
              $iframes.each(function () {
                const src = this.getAttribute('src');
                const ok = src && urlOkMap[src] === true;
                // Add/update a status label beside the iframe for debugging.
                let $status = $(this).next('.fetch-check-status');
                if ($status.length === 0) {
                  $status = $('<div class="fetch-check-status"/>').insertAfter(this);
                }
                const info = urlOkMap[src];
                const statusText = ok ? ('✅ OK ' + (info && typeof info.status !== 'undefined' ? '(' + info.status + ')' : ''))
                                      : ('❌ Failed ' + (info && typeof info.status !== 'undefined' ? '(' + info.status + ')' : ''));
                $status.text(statusText + ' — ' + (src || ''))
                       .css({ margin: '6px 0 18px', font: '14px/1.2 system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif', color: ok ? '#16a34a' : '#ef4444', wordBreak: 'break-all' });

                if (!ok) {
                  // Hide only the iframe, keep the status visible.
                  this.style.display = 'none';
                  if (nid && isbn && token) {
                    $.ajax({
                      url: Drupal.url('wlt-bookshop/report-bad-isbn/' + nid) + '?value=' + encodeURIComponent(isbn) + '&token=' + encodeURIComponent(token),
                      method: 'POST',
                      dataType: 'json'
                    });
                  }
                }
              });

              // If every iframe failed, also hide the entire container.
              if (!anyOk) {
                $container.hide();
              }
            });
          }
        }, 3500);
      });
    }
  };
})(jQuery, Drupal);
