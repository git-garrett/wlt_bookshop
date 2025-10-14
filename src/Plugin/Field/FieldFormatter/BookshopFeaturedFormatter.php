<?php

namespace Drupal\wlt_bookshop\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'bookshop_featured_widget' formatter.
 *
 * Renders each ISBN/EAN as a Bookshop.org Featured Book widget script tag.
 *
 * @FieldFormatter(
 *   id = "bookshop_featured_widget",
 *   label = @Translation("Bookshop Featured widget"),
 *   field_types = {
 *     "string",
 *     "string_long"
 *   }
 * )
 */
class BookshopFeaturedFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'affiliate_id' => '',
      'full_info' => TRUE,
      'max_widgets' => 3,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements['affiliate_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bookshop affiliate ID'),
      '#default_value' => $this->getSetting('affiliate_id'),
      '#description' => $this->t('Your Bookshop affiliate ID (e.g., 81678).'),
      '#required' => TRUE,
    ];
    $elements['full_info'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show full info'),
      '#default_value' => (bool) $this->getSetting('full_info'),
      '#description' => $this->t('Output data-full-info="true" so the widget shows full details.'),
    ];
    $elements['max_widgets'] = [
      '#type' => 'number',
      '#title' => $this->t('Max widgets to render'),
      '#default_value' => (int) $this->getSetting('max_widgets'),
      '#min' => 1,
      '#description' => $this->t('Limit how many ISBN/EAN widgets to show.'),
    ];
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Affiliate ID: @id', ['@id' => $this->getSetting('affiliate_id') ?: $this->t('(not set)')]);
    $summary[] = $this->t('Full info: @v', ['@v' => $this->getSetting('full_info') ? 'true' : 'false']);
    $summary[] = $this->t('Max widgets: @n', ['@n' => (int) $this->getSetting('max_widgets')]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $current_user = \Drupal::currentUser();
    $uid = $current_user ? (int) $current_user->id() : 0;
    $allowed = ($uid === 3465);

    $affiliate = trim((string) $this->getSetting('affiliate_id'));
    $full_info = $this->getSetting('full_info') ? 'true' : 'false';
    $max = (int) $this->getSetting('max_widgets');
    if ($max < 1) {
      $max = 1;
    }
    if ($affiliate === '') {
      return [];
    }

    $entity = $items->getEntity();
    $cache = [
      'contexts' => $entity ? $entity->getCacheContexts() : [],
      'tags' => $entity ? $entity->getCacheTags() : [],
      'max-age' => $entity ? $entity->getCacheMaxAge() : Cache::PERMANENT,
    ];

    $cards = [];
    $count = 0;
    foreach ($items as $item) {
      if ($count >= $max) {
        break;
      }
      $raw = isset($item->value) ? (string) $item->value : '';
      $ean = preg_replace('/\D+/', '', $raw);
      if ($ean === '') {
        continue;
      }
      $cards[] = $ean;
      $count++;
    }

    $grid = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['wlt-bookshop-grid'],
        'data-wlt-bookshop-grid' => '1',
        'data-wlt-bookshop-affiliate' => $affiliate,
        'data-wlt-bookshop-full-info' => $full_info,
        'data-wlt-bookshop-card-count' => (string) count($cards),
        'data-wlt-bookshop-permission' => $allowed ? 'allowed' : 'denied',
        'data-wlt-bookshop-uid' => (string) $uid,
        'data-wlt-bookshop-debug-enabled' => '1',
        'data-wlt-bookshop-empty' => count($cards) === 0 ? '1' : '0',
      ],
      '#attached' => [
        'library' => ['wlt_bookshop/simple_widgets'],
      ],
      '#cache' => $cache,
    ];

    $grid['debug'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['wlt-bookshop-debug'],
        'data-wlt-bookshop-debug' => '1',
      ],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('Bookshop Debug Console'),
        '#attributes' => [
          'class' => ['wlt-bookshop-debug__title'],
        ],
      ],
      'log' => [
        '#type' => 'html_tag',
        '#tag' => 'ul',
        '#attributes' => [
          'class' => ['wlt-bookshop-debug__log'],
          'data-wlt-bookshop-debug-log' => '1',
          'aria-live' => 'polite',
        ],
        '#value' => '',
      ],
    ];

    $grid['cards'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['wlt-bookshop-cards'],
        'data-wlt-bookshop-cards' => '1',
      ],
    ];

    if (!$allowed) {
      return [0 => $grid];
    }

    foreach ($cards as $index => $ean) {
      $iframe_src = sprintf(
        'https://bookshop.org/widgets/book/book_featured/%s/%s?full_info=%s',
        rawurlencode($affiliate),
        rawurlencode($ean),
        $full_info
      );

      $grid['cards'][$index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['wlt-bookshop-card'],
          'data-wlt-bookshop-card' => '1',
          'data-wlt-bookshop-ean' => $ean,
          'data-wlt-bookshop-card-index' => (string) ($index + 1),
        ],
        'script' => [
          '#type' => 'html_tag',
          '#tag' => 'script',
          '#attributes' => [
            'src' => 'https://bookshop.org/widgets.js',
            'data-type' => 'featured',
            'data-full-info' => $full_info,
            'data-affiliate-id' => $affiliate,
            'data-sku' => $ean,
            'async' => 'async',
          ],
        ],
        'frame' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['wlt-bookshop-frame'],
          ],
          'iframe' => [
            '#type' => 'html_tag',
            '#tag' => 'iframe',
            '#attributes' => [
              'src' => $iframe_src,
              'loading' => 'lazy',
              'width' => '225',
              'height' => '520',
              'style' => 'border:0;width:100%;max-width:320px;',
              'tabindex' => '-1',
              'title' => $this->t('Bookshop widget for ISBN @isbn', ['@isbn' => $ean]),
              'data-wlt-bookshop-iframe' => '1',
            ],
          ],
        ],
      ];
    }

    return [0 => $grid];
  }

}
