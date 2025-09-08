(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.wltBookshopFeatured = {
    attach: function (context) {
      $('.wlt-bookshop-featured-container', context).once('wlt-bookshop-featured').each(function () {
        const $container = $(this);
        const nid = $container.data('nid');
        const isbn = $container.data('isbn');
        const token = $container.data('report-token');

        // After a delay, if no iframe or zero-height iframe, hide.
        setTimeout(function () {
          const $iframe = $container.find('iframe');
          let broken = false;
          if ($iframe.length === 0) {
            broken = true;
          }
          else {
            const h = $iframe[0].clientHeight || $iframe.height();
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
