<?php

namespace Drupal\wlt_bookshop\Commands;

use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\wlt_bookshop\Form\BookIsbnProcessForm;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the WLT Bookshop module.
 */
class WltBookshopCommands extends DrushCommands {

  /**
   * Form builder service.
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * Construct the command handler.
   */
  public function __construct(FormBuilderInterface $form_builder) {
    parent::__construct();
    $this->formBuilder = $form_builder;
  }

  /**
   * Run the ISBN batch processor via Drush.
   *
   * @command wlt-bookshop:process-isbn
   * @aliases wlt-bookshop-process
   *
   * @option nid Process a specific node ID.
   * @option limit Maximum number of nodes (default 25, ignored with --nid or --process-all).
   * @option process-all When set, walks every eligible node in batches (default FALSE).
   * @option batch-size Size of each chunk when processing multiple nodes (default 100).
   * @option debug Collect and display per-node debug output (default FALSE).
   */
  public function processIsbn(array $options = [
    'nid' => NULL,
    'limit' => 25,
    'process-all' => FALSE,
    'batch-size' => 100,
    'debug' => FALSE,
  ]): void {
    $nid = isset($options['nid']) ? (int) $options['nid'] : 0;
    $limit = max(1, min(500, (int) ($options['limit'] ?? 25)));
    $processAll = !empty($options['process-all']);
    $batchSize = max(1, min(500, (int) ($options['batch-size'] ?? 100)));
    $debug = !empty($options['debug']);

    $values = [
      'nid' => $nid,
      'limit' => $limit,
      'process_all' => $processAll,
      'batch_size' => $batchSize,
      'debug' => $debug,
    ];

    $form_state = (new FormState())
      ->setValues($values)
      ->setUserInput($values);
    $form_state->setSubmitted();

    $this->formBuilder->submitForm(BookIsbnProcessForm::class, $form_state);

    $batch = &batch_get();
    if (empty($batch)) {
      $this->logger()->notice('No batch was started (nothing to process).');
      return;
    }

    $batch['progressive'] = FALSE;

    if (function_exists('drush_backend_batch_process')) {
      drush_backend_batch_process();
    }
    else {
      batch_process();
    }
  }

}

