(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.wltBookshopFeatured = {
    attach: function (context) {
      $('.wlt-bookshop-featured-container', context).once('wlt-bookshop-featured').each(function () {
        const $container = $(this);
        const canRemove = $container.data('can-remove') === 1 || $container.data('can-remove') === '1';
        const nid = $container.data('nid');
        const isbn = $container.data('isbn');
        const token = $container.data('remove-token');

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

            if (canRemove && nid && isbn && token) {
              const $actions = $container.find('.wlt-bookshop-featured-actions');
              if ($actions.length) {
                $actions.show();
              }
              // Bind click to remove link to call backend and then remove container.
              $container.find('.wlt-bookshop-remove-link').on('click', function (e) {
                e.preventDefault();
                const url = $(this).attr('href');
                $.ajax({
                  url: url,
                  method: 'POST',
                  dataType: 'json',
                  success: function (res) {
                    // Remove container on success.
                    if (res && res.removed) {
                      $container.remove();
                    }
                  }
                });
              });
            }
          }
        }, 3500);
      });
    }
  };
})(jQuery, Drupal);

