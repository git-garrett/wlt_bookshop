<?php

namespace Drupal\wlt_bookshop\Commands;

use Drupal\Core\Form\FormState;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\wlt_bookshop\Form\BookIsbnProcessForm;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the WLT Bookshop module.
 */
class WltBookshopCommands extends DrushCommands {

  /**
   * Class resolver service.
   */
  protected ClassResolverInterface $classResolver;

  /**
   * Construct the command handler.
   */
  public function __construct(ClassResolverInterface $class_resolver) {
    parent::__construct();
    $this->classResolver = $class_resolver;
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
   * @option api-verbose Output verbose Open Library request/response logging (default FALSE).
   */
  public function processIsbn(array $options = [
    'nid' => NULL,
    'limit' => 25,
    'process-all' => FALSE,
    'batch-size' => 100,
    'debug' => FALSE,
    'api-verbose' => FALSE,
  ]): void {
    $nid = isset($options['nid']) ? (int) $options['nid'] : 0;
    $limit = max(1, min(500, (int) ($options['limit'] ?? 25)));
    $processAll = !empty($options['process-all']);
    $batchSize = max(1, min(500, (int) ($options['batch-size'] ?? 100)));
    $debug = !empty($options['debug']);
    $apiVerbose = !empty($options['api-verbose']);

    $values = [
      'nid' => $nid > 0 ? $nid : '',
      'limit' => $limit,
      'process_all' => $processAll,
      'batch_size' => $batchSize,
      'debug' => $debug,
      'api_verbose' => $apiVerbose,
    ];

    /** @var \Drupal\wlt_bookshop\Form\BookIsbnProcessForm $form_object */
    $form_object = $this->classResolver->getInstanceFromDefinition(BookIsbnProcessForm::class);
    $form_state = (new FormState())
      ->setValues($values)
      ->setUserInput($values);
    $form_state->setFormObject($form_object);
    $form_state->setSubmitted();

    $form = ['#form_id' => $form_object->getFormId()];
    $form = $form_object->buildForm($form, $form_state);
    $form_state->setCompleteForm($form);
    $form_object->validateForm($form, $form_state);

    if ($form_state->hasAnyErrors()) {
      foreach ($form_state->getErrors() as $error) {
        $this->logger()->error($error);
      }
      throw new \RuntimeException('Validation failed; see log output.');
    }

    $form_object->submitForm($form, $form_state);

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
