(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.wltBookshopFeatured = {
    attach: function (context) {
      $('.wlt-bookshop-featured-container', context).once('wlt-bookshop-featured').each(function () {
        const $container = $(this);
        const nid = $container.data('nid');
        const isbn = $container.data('isbn');
        const token = $container.data('report-token');

        // Heuristic 1: If the embedded script is missing data-sku, hide + report immediately.
        const $script = $container.find('script[data-type="featured"]').first();
        const sku = $script.attr('data-sku');
        if (!$script.length || !sku || String(sku).trim() === '') {
          $container.hide();
          if (nid && isbn && token) {
            $.ajax({
              url: Drupal.url('wlt-bookshop/report-bad-isbn/' + nid) + '?value=' + encodeURIComponent(isbn) + '&token=' + encodeURIComponent(token),
              method: 'POST',
              dataType: 'json'
            });
          }
          return; // Skip further checks for this container.
        }

        // After a delay, if no iframe or zero-height iframe, hide.
        setTimeout(function () {
          const $iframe = $container.find('iframe');
          let broken = false;
          if ($iframe.length === 0) {
            broken = true;
          }
          else {
            const h = $iframe[0].clientHeight || $iframe.height();
            // Some failures still set an explicit height; as a fallback,
            // treat iframes with no visible content box as broken as well.
            if (!h || h < 20) {
              broken = true;
            }
          }

          if (broken) {
            $container.hide();
            // Report bad ISBN silently so it can be suppressed for 30 days.
            if (nid && isbn && token) {
              $.ajax({
                url: Drupal.url('wlt-bookshop/report-bad-isbn/' + nid) + '?value=' + encodeURIComponent(isbn) + '&token=' + encodeURIComponent(token),
                method: 'POST',
                dataType: 'json'
              });
            }
          }
        }, 3500);
      });
    }
  };
})(jQuery, Drupal);
