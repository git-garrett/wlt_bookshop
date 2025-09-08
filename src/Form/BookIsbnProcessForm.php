<?php

namespace Drupal\wlt_bookshop\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

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

    /** @var \Drupal\wlt_bookshop\Service\BookIsbnLookup $lookup */
    $lookup = \Drupal::service('wlt_bookshop.book_isbn_lookup');
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $logger = \Drupal::logger('wlt_bookshop');

    // If a specific NID is provided, process only that node.
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
      $authors = $this->getAuthorNames($node);
      $title = $this->getSearchTitle($node);
      if ($title === '' && empty($authors)) {
        $this->messenger()->addStatus($this->t('Node @nid has no usable title/author to search.', ['@nid' => $specific_nid]));
        return;
      }

      // Collect all ISBNs from service and append missing to field.
      $found = [];
      // Use Bookshop search by title + authors to get EANs.
      $debugInfo = [];
      if (method_exists($lookup, 'getEansByTitleAuthor')) {
        if ($debugEnabled) {
          $foundList = $lookup->getEansByTitleAuthor($title, $authors, $debugInfo);
        }
        else {
          $foundList = $lookup->getEansByTitleAuthor($title, $authors);
        }
      }
      else {
        // Fallback: try author-only OpenLibrary flow if the method is missing.
        if ($debugEnabled) {
          $tmp = [];
          $foundList = [];
          foreach ($authors as $a) {
            $list = method_exists($lookup, 'getIsbnsByAuthor') ? $lookup->getIsbnsByAuthor($a, $tmp) : array_filter([(string) $lookup->getIsbnByAuthor($a, $tmp)]);
            $foundList = array_merge($foundList, $list);
          }
          $debugInfo = $tmp;
        }
        else {
          $foundList = [];
          foreach ($authors as $a) {
            $list = method_exists($lookup, 'getIsbnsByAuthor') ? $lookup->getIsbnsByAuthor($a) : array_filter([(string) $lookup->getIsbnByAuthor($a)]);
            $foundList = array_merge($foundList, $list);
          }
        }
      }
      foreach ((array) $foundList as $isbn) {
        $found[(string) $isbn] = TRUE;
      }

      // Merge with existing values, dedupe.
      $existing_items = $node->get('field_isbn')->getValue();
      $existing = [];
      foreach ($existing_items as $item) {
        if (isset($item['value']) && $item['value'] !== '') {
          $existing[$item['value']] = TRUE;
        }
      }
      $added = 0;
      foreach (array_keys($found) as $isbn) {
        $isbn = (string) $isbn;
        if ($isbn === '') { continue; }
        if (!isset($existing[$isbn])) {
          $existing[$isbn] = TRUE;
          $added++;
        }
      }

      if ($added > 0) {
        $items = [];
        foreach (array_keys($existing) as $isbn) {
          $items[] = ['value' => $isbn];
        }
        try {
          $node->set('field_isbn', $items);
          $node->save();
          $this->messenger()->addStatus($this->t('Added @added ISBN(s) to node @nid (total @total).', [
            '@added' => $added,
            '@nid' => $specific_nid,
            '@total' => count($items),
          ]));
          $logger->notice('Manual process added @added ISBN(s) on node @nid.', ['@added' => $added, '@nid' => $specific_nid]);
        }
        catch (\Throwable $e) {
          $logger->error('Failed saving ISBNs to node @nid: @message', [
            '@nid' => $node->id(),
            '@message' => $e->getMessage(),
          ]);
          $this->messenger()->addError($this->t('Failed saving ISBNs on node @nid.', ['@nid' => $specific_nid]));
        }
      }
      else {
        $this->messenger()->addStatus($this->t('No new ISBNs found for node @nid (authors: @authors).', [
          '@nid' => $specific_nid,
          '@authors' => implode(', ', $authors),
        ]));
      }

      if ($debugEnabled) {
        $savedIsbns = array_keys($existing);
        $debugSummaryInfo = (is_array($debugInfo) && !empty($debugInfo)) ? reset($debugInfo) : [];
        $summary = $this->formatDebugSummary($specific_nid, trim($title . ' ' . implode(', ', $authors)), $debugSummaryInfo, $savedIsbns);
        $form_state->set('debug_output', $summary);
        $form_state->setRebuild(TRUE);
      }
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

    /** @var \Drupal\node\Entity\Node[] $nodes */
    $nodes = $storage->loadMultiple($nids);
    $updated = 0;
    $checked = 0;

    $debugCombined = '';
    foreach ($nodes as $node) {
      $checked++;
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if ($node->bundle() !== 'book_review') {
        continue;
      }
      if (!$node->hasField('field_author') || !$node->hasField('field_isbn')) {
        continue;
      }
      $authors = $this->getAuthorNames($node);
      $title = $this->getSearchTitle($node);
      if ($title === '' && empty($authors)) {
        continue;
      }

      // Gather all ISBNs and set multi-value field with de-duplication.
      $found = [];
      $debugInfo = [];
      if (method_exists($lookup, 'getEansByTitleAuthor')) {
        $foundList = $debugEnabled ? $lookup->getEansByTitleAuthor($title, $authors, $debugInfo) : $lookup->getEansByTitleAuthor($title, $authors);
      }
      else {
        // Fallback to author-only flow when new method is missing.
        $foundList = [];
        foreach ($authors as $a) {
          $list = $debugEnabled
            ? (method_exists($lookup, 'getIsbnsByAuthor') ? $lookup->getIsbnsByAuthor($a, $debugInfo) : array_filter([(string) $lookup->getIsbnByAuthor($a, $debugInfo)]))
            : (method_exists($lookup, 'getIsbnsByAuthor') ? $lookup->getIsbnsByAuthor($a) : array_filter([(string) $lookup->getIsbnByAuthor($a)]));
          $foundList = array_merge($foundList, $list);
        }
      }
      foreach ((array) $foundList as $isbn) {
        $found[(string) $isbn] = TRUE;
      }
      if (!empty($found)) {
        // Existing values (if any) are not expected here due to query, but handle anyway.
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
          if ($isbn === '') { continue; }
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
          $updated += $added_here > 0 ? 1 : 0;
        }
        catch (\Throwable $e) {
          $logger->error('Failed saving ISBNs to node @nid: @message', [
            '@nid' => $node->id(),
            '@message' => $e->getMessage(),
          ]);
        }
      }

      if ($debugEnabled) {
        $savedIsbns = isset($items) ? array_map(function($i) { return $i['value']; }, $items) : [];
        $debugCombined .= $this->formatDebugSummary($node->id(), trim($title . ' ' . implode(', ', $authors)), $debugInfo, $savedIsbns) . "\n\n";
      }
    }

    if ($updated > 0) {
      $this->messenger()->addStatus($this->t('Updated ISBN on @count book review nodes (checked @checked).', [
        '@count' => $updated,
        '@checked' => $checked,
      ]));
      $logger->notice('Manual process updated ISBN on @count book review nodes.', ['@count' => $updated]);
    }
    else {
      $this->messenger()->addStatus($this->t('Processed @checked nodes, no updates were necessary.', [
        '@checked' => $checked,
      ]));
    }

    if ($debugEnabled) {
      $form_state->set('debug_output', $debugCombined);
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * Extract author name strings from field_author.
   */
  protected function getAuthorNames(NodeInterface $node): array {
    if (!$node->hasField('field_author') || $node->get('field_author')->isEmpty()) {
      return [];
    }
    $names = [];
    foreach ($node->get('field_author') as $item) {
      // For entity reference, use the referenced entity label.
      if (isset($item->entity) && $item->entity) {
        $label = trim((string) $item->entity->label());
        if ($label !== '') {
          $names[$label] = TRUE;
          continue;
        }
      }
      // For non-entity fields (e.g., text), fall back to value.
      if (isset($item->value)) {
        $val = trim((string) $item->value);
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
  protected function getSearchTitle(NodeInterface $node): string {
    if ($node->hasField('field_sidebartitle')) {
      $val = trim((string) $node->get('field_sidebartitle')->value);
      if ($val !== '') {
        return $val;
      }
    }
    return trim((string) $node->label());
  }

  /**
   * Format debug details into a readable text block.
   */
  protected function formatDebugSummary(int $nid, string $author, array $debugInfo, array $savedIsbns): string {
    $lines = [];
    $lines[] = 'Node NID: ' . $nid;
    $lines[] = 'Author: ' . $author;
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
