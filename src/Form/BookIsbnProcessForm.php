<?php

namespace Drupal\wlt_bookshop\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;
use Drupal\wlt_bookshop\Service\BookIsbnLookup;

/**
 * Admin form to trigger ISBN lookup for book reviews.
 */
class BookIsbnProcessForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wlt_bookshop_isbn_process_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['description'] = [
      '#markup' => $this->t('Finds book_review nodes missing an ISBN and attempts to populate it using Open Library based on the value of field_author.'),
    ];

    $form['nid'] = [
      '#type' => 'number',
      '#title' => $this->t('Specific node ID (optional)'),
      '#description' => $this->t('Process a single book_review node by its NID. When provided, the limit below is ignored.'),
      '#min' => 1,
    ];

    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Items to process'),
      '#default_value' => 25,
      '#min' => 1,
      '#max' => 500,
      '#description' => $this->t('Maximum number of nodes to process in this run.'),
      '#required' => TRUE,
    ];
    $form['process_all'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Process all book reviews needing ISBNs'),
      '#description' => $this->t('When checked, the form will continue through every matching book_review node in batches. Leave unchecked to process only up to the limit above.'),
    ];
    $form['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#default_value' => 100,
      '#min' => 1,
      '#max' => 500,
      '#description' => $this->t('How many nodes to load and process at a time when running against the entire set.'),
      '#states' => [
        'visible' => [
          ':input[name="process_all"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="process_all"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show debug details'),
      '#description' => $this->t('Display author, API queries, responses summary, and stored values.'),
      '#default_value' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Process now'),
      '#button_type' => 'primary',
    ];

    // Show debug output block if set during submit.
    $debug_output = $form_state->get('debug_output');
    if (!is_string($debug_output) || $debug_output === '') {
      try {
        $store = \Drupal::service('user.private_tempstore')->get('wlt_bookshop');
        $stored = $store->get('isbn_debug_output');
        if (is_string($stored) && $stored !== '') {
          $debug_output = $stored;
          $store->delete('isbn_debug_output');
        }
      }
      catch (\Throwable $e) {
        $debug_output = '';
      }
    }
    if (is_string($debug_output) && $debug_output !== '') {
      $form['debug_output'] = [
        '#type' => 'details',
        '#title' => $this->t('Debug output'),
        '#open' => TRUE,
        'pre' => [
          '#type' => 'html_tag',
          '#tag' => 'pre',
          '#value' => $debug_output,
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $limit = (int) $form_state->getValue('limit');
    $limit = max(1, min(500, $limit));
    $specific_nid = (int) $form_state->getValue('nid');
    $debugEnabled = (bool) $form_state->getValue('debug');
    $processAll = (bool) $form_state->getValue('process_all');
    $batchSize = (int) $form_state->getValue('batch_size');
    $batchSize = max(1, min(500, $batchSize ?: 100));

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $nids = [];

    // If a specific NID is provided, validate and process only that node.
    if ($specific_nid > 0) {
      $node = $storage->load($specific_nid);
      if (!$node instanceof NodeInterface) {
        $this->messenger()->addError($this->t('Node @nid not found.', ['@nid' => $specific_nid]));
        return;
      }
      if ($node->bundle() !== 'book_review') {
        $this->messenger()->addError($this->t('Node @nid is not a book_review.', ['@nid' => $specific_nid]));
        return;
      }
      if (!$node->hasField('field_author') || !$node->hasField('field_isbn')) {
        $this->messenger()->addError($this->t('Node @nid is missing required fields.', ['@nid' => $specific_nid]));
        return;
      }
      $authors = static::getAuthorNames($node);
      $title = static::getSearchTitle($node);
      if ($title === '' && empty($authors)) {
        $this->messenger()->addStatus($this->t('Node @nid has no usable title/author to search.', ['@nid' => $specific_nid]));
        return;
      }
      $this->startBatch([[ $specific_nid ]], $debugEnabled);
      return;
    }

    if ($processAll) {
      $query = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', 'book_review')
        ->exists('field_author')
        ->notExists('field_isbn')
        ->sort('changed', 'DESC');

      $all_nids = $query->execute();
      if (empty($all_nids)) {
        $this->messenger()->addStatus($this->t('No book_review nodes require processing.'));
        return;
      }

      $chunks = array_chunk($all_nids, $batchSize);
      $this->startBatch($chunks, $debugEnabled);
      return;
    }

    // Otherwise, process a batch of nodes up to the limit.
    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'book_review')
      ->exists('field_author')
      ->notExists('field_isbn')
      ->range(0, $limit)
      ->sort('changed', 'DESC');

    $nids = $query->execute();
    if (empty($nids)) {
      $this->messenger()->addStatus($this->t('No book_review nodes require processing.'));
      return;
    }

    $chunks = array_chunk($nids, max(1, min($batchSize, count($nids))));
    $this->startBatch($chunks, $debugEnabled);
  }

  /**
   * Kick off a Drupal batch for ISBN processing.
   *
   * @param array[] $chunks
   *   Nested arrays of node IDs to process per operation.
   * @param bool $debugEnabled
   *   Whether debug output should be collected.
   */
  protected function startBatch(array $chunks, bool $debugEnabled): void {
    $operations = [];
    foreach ($chunks as $chunk) {
      $operations[] = [
        [static::class, 'batchProcess'],
        [$chunk, $debugEnabled],
      ];
    }

    $batch = [
      'title' => $this->t('Processing book review ISBN lookups'),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinished'],
      'init_message' => $this->t('Starting ISBN lookup...'),
      'progress_message' => $this->t('Processed @current of @total batches.'),
      'error_message' => $this->t('The ISBN processing batch encountered an error.'),
    ];

    if ($debugEnabled) {
      $batch['results']['debug_enabled'] = TRUE;
    }

    batch_set($batch);
  }

  /**
   * Batch operation callback.
   *
   * @param int[] $nids
   *   Node IDs to process in this operation.
   * @param bool $debugEnabled
   *   TRUE when debug output should be aggregated.
   * @param array $context
   *   Batch context array.
   */
  public static function batchProcess(array $nids, bool $debugEnabled, array &$context): void {
    /** @var \Drupal\wlt_bookshop\Service\BookIsbnLookup $lookup */
    $lookup = \Drupal::service('wlt_bookshop.book_isbn_lookup');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    /** @var \Drupal\node\Entity\Node[] $nodes */
    $nodes = $storage->loadMultiple($nids);
    $logger = \Drupal::logger('wlt_bookshop');

    $stats = [
      'checked' => 0,
      'updated' => 0,
    ];
    $debugCombined = '';

    static::processNodes($nodes, $lookup, $debugEnabled, $logger, $stats, $debugCombined);

    $context['results']['checked'] = ($context['results']['checked'] ?? 0) + $stats['checked'];
    $context['results']['updated'] = ($context['results']['updated'] ?? 0) + $stats['updated'];
    $context['results']['operations'] = ($context['results']['operations'] ?? 0) + 1;
    if ($debugEnabled && $debugCombined !== '') {
      $context['results']['debug'][] = $debugCombined;
    }

    $context['message'] = \Drupal::translation()->translate('Processed @count nodes so far.', [
      '@count' => $context['results']['checked'],
    ]);
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    $logger = \Drupal::logger('wlt_bookshop');

    if (!$success) {
      $messenger->addError(t('The ISBN processing batch did not complete.'));
      return;
    }

    $checked = $results['checked'] ?? 0;
    $updated = $results['updated'] ?? 0;

    if ($updated > 0) {
      $messenger->addStatus(t('Updated ISBN on @count book review nodes (checked @checked).', [
        '@count' => $updated,
        '@checked' => $checked,
      ]));
      $logger->notice('Manual process updated ISBN on @count book review nodes.', ['@count' => $updated]);
    }
    else {
      $messenger->addStatus(t('Processed @checked nodes, no updates were necessary.', [
        '@checked' => $checked,
      ]));
    }

    if (!empty($results['debug'])) {
      $debug = implode("\n\n", $results['debug']);
      try {
        $store = \Drupal::service('user.private_tempstore')->get('wlt_bookshop');
        $store->set('isbn_debug_output', $debug);
      }
      catch (\Throwable $e) {
        // Ignore tempstore failures; debug output just won't display.
      }
    }
  }

  /**
   * Process a set of nodes, applying ISBN lookups and collecting stats.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Loaded node entities to inspect.
   * @param \Drupal\wlt_bookshop\Service\BookIsbnLookup $lookup
   *   Lookup service for retrieving ISBNs.
   * @param bool $debugEnabled
   *   TRUE when debug output should be collected.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   Logger for failure notices.
   * @param array $stats
   *   Mutable array storing 'checked' and 'updated' counters.
   * @param string $debugCombined
   *   Aggregated debug output string.
   */
  protected static function processNodes(array $nodes, BookIsbnLookup $lookup, bool $debugEnabled, LoggerChannelInterface $logger, array &$stats, string &$debugCombined): void {
    foreach ($nodes as $node) {
      $stats['checked']++;
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if ($node->bundle() !== 'book_review') {
        continue;
      }
      if (!$node->hasField('field_author') || !$node->hasField('field_isbn')) {
        continue;
      }
      if (wlt_bookshop_is_kill_switch_enabled($node)) {
        continue;
      }
      $authors = static::getAuthorNames($node);
      $title = static::getSearchTitle($node);
      if ($title === '' && empty($authors)) {
        continue;
      }

      $found = [];
      $debugInfo = $debugEnabled ? [] : NULL;
      foreach ($authors as $authorName) {
        if ($authorName === '') {
          continue;
        }
        $list = [];
        if (method_exists($lookup, 'getIsbnsByAuthor')) {
          $list = $debugEnabled
            ? $lookup->getIsbnsByAuthor($authorName, $debugInfo)
            : $lookup->getIsbnsByAuthor($authorName);
        }
        elseif (method_exists($lookup, 'getIsbnByAuthor')) {
          $single = $debugEnabled
            ? $lookup->getIsbnByAuthor($authorName, $debugInfo)
            : $lookup->getIsbnByAuthor($authorName);
          $list = $single ? [$single] : [];
        }
        foreach ((array) $list as $isbn) {
          $normalized = \wlt_bookshop_normalize_isbn((string) $isbn);
          if ($normalized) {
            $found[$normalized] = TRUE;
          }
        }
      }

      $items = NULL;
      if (!empty($found)) {
        $existing_items = $node->get('field_isbn')->getValue();
        $existing = [];
        foreach ($existing_items as $item) {
          if (isset($item['value']) && $item['value'] !== '') {
            $existing[$item['value']] = TRUE;
          }
        }
        $before = count($existing);
        foreach (array_keys($found) as $isbn) {
          $isbn = (string) $isbn;
          if ($isbn === '') {
            continue;
          }
          $existing[$isbn] = TRUE;
        }
        $items = [];
        foreach (array_keys($existing) as $isbn) {
          $items[] = ['value' => $isbn];
        }
        try {
          $node->set('field_isbn', $items);
          $node->save();
          $added_here = count($items) - $before;
          if ($added_here > 0) {
            $stats['updated']++;
          }
        }
        catch (\Throwable $e) {
          $logger->error('Failed saving ISBNs to node @nid: @message', [
            '@nid' => $node->id(),
            '@message' => $e->getMessage(),
          ]);
          // Skip collecting debug for this node if save failed.
          $items = NULL;
        }
      }

      if ($debugEnabled) {
        $savedIsbns = [];
        if (isset($items)) {
          foreach ((array) $items as $item) {
            if (isset($item['value'])) {
              $savedIsbns[] = $item['value'];
            }
          }
        }
        $debugCombined .= static::formatDebugSummary($node->id(), $title, $authors, is_array($debugInfo) ? $debugInfo : [], $savedIsbns) . "\n\n";
      }
    }
  }

  /**
   * Extract author name strings from field_author.
   */
  protected static function getAuthorNames(NodeInterface $node): array {
    $field = wlt_bookshop_get_bundle_field($node->bundle(), 'author_field') ?? 'field_author';
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return [];
    }
    $names = [];
    foreach ($node->get($field) as $item) {
      // For entity reference, use the referenced entity label.
      if (isset($item->entity) && $item->entity) {
        $label = static::sanitizeText((string) $item->entity->label());
        if ($label !== '') {
          $names[$label] = TRUE;
          continue;
        }
      }
      // For non-entity fields (e.g., text), fall back to value.
      if (isset($item->value)) {
        $val = static::sanitizeText((string) $item->value);
        if ($val !== '') {
          $names[$val] = TRUE;
        }
      }
    }
    return array_keys($names);
  }

  /**
   * Resolve a title string for search.
   * Prefers custom field `field_sidebartitle`, falls back to node label().
   */
  protected static function getSearchTitle(NodeInterface $node): string {
    if ($node->hasField('field_sidebartitle')) {
      $val = static::sanitizeText((string) $node->get('field_sidebartitle')->value);
      if ($val !== '') {
        return $val;
      }
    }
    return static::sanitizeText((string) $node->label());
  }

  /**
   * Strip HTML and normalize whitespace in user-provided text.
   */
  protected static function sanitizeText(string $text): string {
    if ($text === '') { return ''; }
    // Decode entities, remove tags, collapse whitespace.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = strip_tags($text);
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    return $text;
  }

  /**
   * Format debug details into a readable text block.
   */
  protected static function formatDebugSummary(int $nid, string $title, array $authors, array $debugInfo, array $savedIsbns): string {
    $lines = [];
    $lines[] = 'Node NID: ' . $nid;
    $lines[] = 'Title: ' . $title;
    $lines[] = 'Authors: ' . (empty($authors) ? '-' : implode(', ', $authors));
    // Show the exact keywords used for Bookshop search when available.
    $keywords = '';
    if (!empty($debugInfo['bookshop']['query']['keywords'])) {
      $keywords = (string) $debugInfo['bookshop']['query']['keywords'];
    }
    else {
      $kwParts = [];
      if ($title !== '') { $kwParts[] = $title; }
      if (!empty($authors)) { $kwParts[] = implode(' ', $authors); }
      $keywords = implode(' ', $kwParts);
    }
    $lines[] = 'Search keywords: ' . $keywords;
    if (!empty($debugInfo['search'])) {
      $s = $debugInfo['search'];
      $lines[] = 'Search URL: ' . ($s['url'] ?? '');
      if (!empty($s['query'])) {
        $lines[] = 'Search query: ' . json_encode($s['query']);
      }
      if (isset($s['num_docs'])) {
        $lines[] = 'Search num_docs: ' . $s['num_docs'];
      }
      if (isset($s['status'])) {
        $lines[] = 'Search status: ' . $s['status'];
      }
      if (isset($s['error'])) {
        $lines[] = 'Search error: ' . $s['error'];
      }
    }
    if (!empty($debugInfo['bookshop'])) {
      $b = $debugInfo['bookshop'];
      $lines[] = 'Bookshop URL: ' . ($b['url'] ?? '');
      if (!empty($b['query'])) {
        $lines[] = 'Bookshop query: ' . json_encode($b['query']);
      }
      if (!empty($b['links'])) {
        $lines[] = 'Bookshop links: ' . implode(', ', (array) $b['links']);
      }
      if (!empty($b['eans'])) {
        $lines[] = 'Bookshop EANs: ' . implode(', ', (array) $b['eans']);
      }
      if (!empty($b['status'])) {
        $lines[] = 'Bookshop status: ' . $b['status'];
      }
      if (!empty($b['error'])) {
        $lines[] = 'Bookshop error: ' . $b['error'];
      }
    }
    if (!empty($debugInfo['candidate_edition_keys'])) {
      $lines[] = 'Candidate editions: ' . implode(', ', array_keys($debugInfo['candidate_edition_keys']));
    }
    if (!empty($debugInfo['editions'])) {
      $lines[] = 'Edition lookups:';
      $max = 20;
      $i = 0;
      foreach ($debugInfo['editions'] as $ed) {
        if ($i++ >= $max) { $lines[] = '...truncated...'; break; }
        $line = '- ' . ($ed['edition'] ?? '') . ' [' . ($ed['url'] ?? '') . ']';
        if (!empty($ed['isbn_13'])) {
          $line .= ' isbn_13=' . json_encode($ed['isbn_13']);
        }
        if (!empty($ed['isbn_10'])) {
          $line .= ' isbn_10=' . json_encode($ed['isbn_10']);
        }
        if (!empty($ed['status'])) {
          $line .= ' status=' . $ed['status'];
        }
        if (!empty($ed['error'])) {
          $line .= ' error=' . $ed['error'];
        }
        if (!empty($ed['picked'])) {
          $line .= ' picked=' . $ed['picked'];
        }
        $lines[] = $line;
      }
    }
    if (!empty($savedIsbns)) {
      $lines[] = 'Saved ISBNs: ' . implode(', ', $savedIsbns);
    }
    if (!empty($debugInfo['found_isbn'])) {
      $lines[] = 'Found (single) ISBN: ' . $debugInfo['found_isbn'];
    }
    if (!empty($debugInfo['found_isbns'])) {
      $lines[] = 'Found ISBNs: ' . implode(', ', (array) $debugInfo['found_isbns']);
    }
    return implode("\n", $lines);
  }
}
