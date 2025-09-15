<?php

namespace Drupal\wlt_bookshop\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class ReportForm extends FormBase {

  public function getFormId(): string {
    return 'wlt_bookshop_report_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $report = $this->buildDebugReport();

    $form['intro'] = [
      '#markup' => $this->t('This report summarizes Bookshop Featured widget suppressions detected on the front end and a sample of non-suppressed ISBNs for comparison.'),
    ];

    $form['summary'] = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Active suppressions: @n', ['@n' => $report['suppressed_count']]),
        $this->t('Sample non-suppressed ISBNs scanned: @n (from last @m nodes)', [
          '@n' => $report['non_suppressed_count'],
          '@m' => $report['scanned_nodes'],
        ]),
      ],
    ];

    if (!empty($report['suppressed'])) {
      $items = [];
      foreach (array_slice($report['suppressed'], 0, 100) as $row) {
        $items[] = $this->t('Node @nid — ISBN @isbn (expires @exp)', [
          '@nid' => $row['nid'],
          '@isbn' => $row['isbn'],
          '@exp' => $row['expires'],
        ]);
      }
      $form['suppressed'] = [
        '#type' => 'details',
        '#title' => $this->t('Active suppressions (first 100)'),
        '#open' => FALSE,
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ];
    }

    if (!empty($report['non_suppressed'])) {
      $items = [];
      foreach (array_slice($report['non_suppressed'], 0, 100) as $row) {
        $items[] = $this->t('Node @nid — ISBN @isbn', [
          '@nid' => $row['nid'],
          '@isbn' => $row['isbn'],
        ]);
      }
      $form['non_suppressed'] = [
        '#type' => 'details',
        '#title' => $this->t('Non-suppressed sample (first 100)'),
        '#open' => FALSE,
        'list' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh report'),
    ];

    // Admin-only control to purge all active suppressions immediately.
    if (\Drupal::currentUser()->hasPermission('administer nodes')) {
      $form['actions']['purge'] = [
        '#type' => 'submit',
        '#value' => $this->t('Purge suppression list'),
        '#submit' => ['::purgeSuppressionList'],
        '#attributes' => [
          'onclick' => "return confirm('This will clear all active Bookshop ISBN suppressions. Continue?');",
        ],
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild(TRUE);
  }

  /**
   * Form submit handler to purge all ISBN suppressions.
   */
  public function purgeSuppressionList(array &$form, FormStateInterface $form_state): void {
    if (!\Drupal::currentUser()->hasPermission('administer nodes')) {
      $this->messenger()->addError($this->t('You do not have permission to purge suppressions.'));
      return;
    }
    try {
      \Drupal::cache('wlt_bookshop_bad_isbn')->deleteAll();
      $this->messenger()->addStatus($this->t('Suppression list purged.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Failed to purge suppressions: @msg', ['@msg' => $e->getMessage()]));
    }
    $form_state->setRebuild(TRUE);
  }

  /**
   * Build a debug report of suppressed and non-suppressed ISBNs.
   */
  protected function buildDebugReport(): array {
    $report = [
      'suppressed_count' => 0,
      'suppressed' => [],
      'non_suppressed_count' => 0,
      'non_suppressed' => [],
      'scanned_nodes' => 0,
    ];

    $db = \Drupal::database();
    $table = 'cache_wlt_bookshop_bad_isbn';
    $date_formatter = \Drupal::service('date.formatter');
    try {
      if ($db->schema()->tableExists($table)) {
        $query = $db->select($table, 'c')
          ->fields('c', ['cid', 'expire'])
          ->orderBy('expire', 'DESC')
          ->range(0, 500);
        $result = $query->execute();
        $suppressed_keys = [];
        foreach ($result as $row) {
          $cid = (string) $row->cid;
          $expire = (int) $row->expire;
          $nid = NULL; $isbn = NULL;
          if (preg_match('/^nid:(\d+):isbn:(\d+)/', $cid, $m)) {
            $nid = (int) $m[1];
            $isbn = (string) $m[2];
          }
          $report['suppressed'][] = [
            'nid' => $nid ?: '-',
            'isbn' => $isbn ?: $cid,
            'expires' => $expire ? $date_formatter->format($expire, 'short') : $this->t('none'),
          ];
          $suppressed_keys[$cid] = TRUE;
        }
        $report['suppressed_count'] = count($report['suppressed']);

        // Sample non-suppressed by scanning recent nodes.
        $nids = \Drupal::entityQuery('node')
          ->accessCheck(FALSE)
          ->condition('type', 'book_review')
          ->sort('changed', 'DESC')
          ->range(0, 100)
          ->execute();
        if (!empty($nids)) {
          $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);
          $report['scanned_nodes'] = count($nodes);
          foreach ($nodes as $node) {
            if (!$node->hasField('field_isbn') || $node->get('field_isbn')->isEmpty()) { continue; }
            foreach ($node->get('field_isbn') as $item) {
              $val = isset($item->value) ? (string) $item->value : '';
              $ean = preg_replace('/\D+/', '', $val);
              if ($ean === '') { continue; }
              $cid = 'nid:' . $node->id() . ':isbn:' . $ean;
              if (!isset($suppressed_keys[$cid])) {
                $report['non_suppressed'][] = [
                  'nid' => $node->id(),
                  'isbn' => $ean,
                ];
              }
            }
          }
          $report['non_suppressed_count'] = count($report['non_suppressed']);
        }
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('wlt_bookshop')->warning('Failed building suppression report: @msg', ['@msg' => $e->getMessage()]);
    }

    return $report;
  }
}
