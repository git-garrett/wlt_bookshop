(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.wltBookshopFeatured = {
    attach: function (context) {
      // Simple debug logger that renders a sticky panel at the top of the page.
      function dbg(msg) {
        var $panel = $('#wlt-bookshop-debug');
        if ($panel.length === 0) {
          $panel = $('<div id="wlt-bookshop-debug"/>')
            .css({ position: 'sticky', top: 0, background: '#111827', color: '#e5e7eb', padding: '10px', font: '14px/1.2 system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif', zIndex: 99999, borderBottom: '1px solid #374151' })
            .prependTo('body');
          $('<div/>').text('📦 Bookshop widget checker: running…').appendTo($panel);
        }
        var time = new Date().toISOString().split('T')[1].replace('Z','');
        $('<div/>').text('[' + time + '] ' + msg).appendTo($panel);
      }

      dbg('Drupal behavior attached; scanning containers.');
      $('.wlt-bookshop-featured-container', context).once('wlt-bookshop-featured').each(function () {
        const $container = $(this);
        const nid = $container.data('nid');
        const isbn = $container.data('isbn');
        const token = $container.data('report-token');
        dbg('Container start nid=' + nid + ' isbn=' + isbn);

        // After a short delay, check each iframe.src directly.
        setTimeout(function () {
          const $iframes = $container.find('iframe');
          if ($iframes.length === 0) { dbg('No iframes found for nid=' + nid + ' isbn=' + isbn + ' (will skip)'); return; }
          let anyOk = false;
          let pending = $iframes.length;

          $iframes.each(function () {
            const iframe = this;
            const src = iframe.getAttribute('src');
            if (!src) { pending--; dbg('Iframe without src encountered; skipping.'); return; }
            dbg('Checking iframe src=' + src);

            fetch(src, { method: 'GET', mode: 'cors', credentials: 'omit', redirect: 'follow', cache: 'no-store' })
              .then(function (res) {
                if (res && res.ok) {
                  anyOk = true; // keep visible
                  dbg('✅ OK ' + res.status + ' for ' + src);
                } else {
                  // Hide the block for this failing iframe and report.
                  const parent = iframe.parentElement;
                  if (parent) { parent.style.display = 'none'; }
                  dbg('❌ Non-200 (' + (res ? res.status : 'no response') + ') for ' + src + '; hiding iframe block and reporting.');
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
                dbg('🕳️ CORS/network failure for ' + src + '; hiding iframe block and reporting.');
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
                  dbg('Container nid=' + nid + ' isbn=' + isbn + ' — all iframes failed; container hidden.');
                } else if (pending === 0) {
                  dbg('Container nid=' + nid + ' isbn=' + isbn + ' — checks complete; at least one iframe OK.');
                }
              });
          });
        }, 1500);
      });
      dbg('Initial scan complete; timers scheduled.');
    }
  };
})(jQuery, Drupal);
