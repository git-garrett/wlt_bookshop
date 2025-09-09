(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.wltBookshopFeatured = {
    attach: function (context) {
      $('.wlt-bookshop-featured-container', context).once('wlt-bookshop-featured').each(function () {
        const $container = $(this);
        const nid = $container.data('nid');
        const isbn = $container.data('isbn');
        const token = $container.data('report-token');

        // After a short delay, check each iframe.src directly.
        setTimeout(function () {
          const $iframes = $container.find('iframe');
          if ($iframes.length === 0) return;
          let anyOk = false;
          let pending = $iframes.length;

          $iframes.each(function () {
            const iframe = this;
            const src = iframe.getAttribute('src');
            if (!src) { pending--; return; }

            fetch(src, { method: 'GET', mode: 'cors', credentials: 'omit', redirect: 'follow', cache: 'no-store' })
              .then(function (res) {
                if (res && res.ok) {
                  anyOk = true; // keep visible
                } else {
                  // Hide the block for this failing iframe and report.
                  const parent = iframe.parentElement;
                  if (parent) { parent.style.display = 'none'; }
                  if (nid && isbn && token) {
                    $.ajax({
                      url: Drupal.url('wlt-bookshop/report-bad-isbn/' + nid) + '?value=' + encodeURIComponent(isbn) + '&token=' + encodeURIComponent(token),
                      method: 'POST',
                      dataType: 'json'
                    });
                  }
                }
              })
              .catch(function () {
                // Network/CORS failure: treat as non-200.
                const parent = iframe.parentElement;
                if (parent) { parent.style.display = 'none'; }
                if (nid && isbn && token) {
                  $.ajax({
                    url: Drupal.url('wlt-bookshop/report-bad-isbn/' + nid) + '?value=' + encodeURIComponent(isbn) + '&token=' + encodeURIComponent(token),
                    method: 'POST',
                    dataType: 'json'
                  });
                }
              })
              .finally(function () {
                pending--;
                if (pending === 0 && !anyOk) {
                  // All failed → hide entire container.
                  $container.hide();
                }
              });
          });
        }, 1500);
      });
    }
  };
})(jQuery, Drupal);
