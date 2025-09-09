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
    $token_service = \Drupal::service('csrf_token');

    // Attach JS to handle hiding broken widgets and admin removal link.
    $elements['#attached']['library'][] = 'wlt_bookshop/bookshop_featured';

    $count = 0;
    foreach ($items as $delta => $item) {
      if ($count >= $max) { break; }
      $raw = isset($item->value) ? (string) $item->value : '';
      $ean = preg_replace('/\D+/', '', $raw);
      if ($ean === '') { continue; }

      // Skip rendering if this ISBN was recently reported as invalid.
      $suppress_key = 'nid:' . $entity->id() . ':isbn:' . $ean;
      $suppressed = \Drupal::cache('wlt_bookshop_bad_isbn')->get($suppress_key);
      if ($suppressed) {
        continue;
      }

      $container_id = 'wlt-bookshop-featured-' . $entity->id() . '-' . $delta;
      $report_token = $token_service->get('wlt_bookshop:report:' . $entity->id() . ':' . $ean);

      $elements[$delta] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => $container_id,
          'class' => ['wlt-bookshop-featured-container'],
          'data-isbn' => $ean,
          'data-nid' => (string) $entity->id(),
          'data-report-token' => $report_token,
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
        // Inline per-container checker: waits 10s, then HEAD-checks each iframe
        // and hides only failing blocks; hides entire container if all fail.
        'inline_checker' => [
          '#type' => 'html_tag',
          '#tag' => 'script',
          '#value' => Markup::create(
            "(function(){\n" .
            "  var c=document.getElementById('" . $container_id . "'); if(!c) return;\n" .
            "  setTimeout(function(){\n" .
            "    var nid=c.getAttribute('data-nid')||'';\n" .
            "    var isbn=c.getAttribute('data-isbn')||'';\n" .
            "    var token=c.getAttribute('data-report-token')||'';\n" .
            "    var ifr=c.getElementsByTagName('iframe'); if(!ifr.length) return;\n" .
            "    var anyOk=false, pending=ifr.length;\n" .
            "    function report(){ try{ var base=(typeof Drupal!=='undefined'&&Drupal.url)?Drupal.url('wlt-bookshop/report-bad-isbn/'+nid):('/wlt-bookshop/report-bad-isbn/'+nid); fetch(base+'?value='+encodeURIComponent(isbn)+'&token='+encodeURIComponent(token),{method:'POST',credentials:'same-origin'});}catch(e){} }\n" .
            "    Array.prototype.forEach.call(ifr,function(f){\n" .
            "      var src=f.getAttribute('src'); if(!src){ pending--; return; }\n" .
            "      fetch(src,{method:'HEAD',mode:'cors',credentials:'omit'}).then(function(resp){\n" .
            "        if(resp && resp.ok){ anyOk=true; }\n" .
            "        else { var p=f.parentElement; if(p) p.style.display='none'; report(); }\n" .
            "      }).catch(function(){ var p=f.parentElement; if(p) p.style.display='none'; report(); })\n" .
            "      .finally(function(){ pending--; if(pending===0 && !anyOk){ c.style.display='none'; } });\n" .
            "    });\n" .
            "  },10000);\n" .
            "})();"
          ),
        ],
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
