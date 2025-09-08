<?php

namespace Drupal\wlt_bookshop\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;

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
    $elements = [];
    $affiliate = trim((string) $this->getSetting('affiliate_id'));
    $full_info = $this->getSetting('full_info') ? 'true' : 'false';
    $max = (int) $this->getSetting('max_widgets');
    if ($max < 1) { $max = 1; }

    $entity = $items->getEntity();
    $can_remove = $entity->access('update');
    $token_service = \Drupal::service('csrf_token');

    // Attach JS to handle hiding broken widgets and admin removal link.
    $elements['#attached']['library'][] = 'wlt_bookshop/bookshop_featured';

    $count = 0;
    foreach ($items as $delta => $item) {
      if ($count >= $max) { break; }
      $raw = isset($item->value) ? (string) $item->value : '';
      $ean = preg_replace('/\D+/', '', $raw);
      if ($ean === '') { continue; }

      $container_id = 'wlt-bookshop-featured-' . $entity->id() . '-' . $delta;
      $remove_token = $can_remove ? $token_service->get('wlt_bookshop:remove:' . $entity->id() . ':' . $ean) : '';

      $elements[$delta] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => $container_id,
          'class' => ['wlt-bookshop-featured-container'],
          'data-isbn' => $ean,
          'data-nid' => (string) $entity->id(),
          'data-can-remove' => $can_remove ? '1' : '0',
          'data-remove-token' => $remove_token,
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
          ],
        ],
        'actions' => $can_remove ? [
          '#type' => 'container',
          '#attributes' => ['class' => ['wlt-bookshop-featured-actions'], 'style' => 'display:none'],
          'remove' => [
            '#type' => 'link',
            '#title' => $this->t('Remove invalid ISBN'),
            '#url' => \Drupal\Core\Url::fromRoute('wlt_bookshop.remove_isbn', ['node' => $entity->id()], [
              'query' => ['value' => $ean, 'token' => $remove_token],
            ]),
            '#attributes' => ['class' => ['wlt-bookshop-remove-link'], 'data-ajax' => '1'],
          ],
        ] : [],
        '#cache' => [
          'contexts' => $items->getEntity()->getCacheContexts(),
          'tags' => $items->getEntity()->getCacheTags(),
          'max-age' => $items->getEntity()->getCacheMaxAge(),
        ],
      ];
      $count++;
    }

    return $elements;
  }

}
