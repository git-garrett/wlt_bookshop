<?php

namespace Drupal\wlt_bookshop\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for WLT Bookshop bundle settings.
 */
class WltBookshopSettingsForm extends ConfigFormBase {

  /**
   * Provides bundle labels for node entity bundles.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * Field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $fieldManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * Constructs the settings form.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeBundleInfoInterface $bundle_info, EntityFieldManagerInterface $field_manager) {
    parent::__construct($config_factory);
    $this->bundleInfo = $bundle_info;
    $this->fieldManager = $field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wlt_bookshop_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['wlt_bookshop.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('wlt_bookshop.settings');
    $bundle_settings = $config->get('bundle_settings') ?? [];
    $debug_logging = (bool) ($config->get('debug_logging') ?? FALSE);

    $bundles = $this->bundleInfo->getBundleInfo('node');
    if (empty($bundles)) {
      $form['message'] = [
        '#markup' => $this->t('No node bundles are available.'),
      ];
      return parent::buildForm($form, $form_state);
    }

    $enabled_default = array_keys($bundle_settings);
    $bundle_options = [];
    foreach ($bundles as $bundle_id => $bundle) {
      $bundle_options[$bundle_id] = $bundle['label'] ?? $bundle_id;
    }

    $form['debug_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable settings debug logging'),
      '#default_value' => $debug_logging,
      '#description' => $this->t('Write additional diagnostics to the log when viewing or submitting this form. Turn off when not troubleshooting.'),
    ];

    $form['enabled_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enable Bookshop processing for bundles'),
      '#options' => $bundle_options,
      '#default_value' => $enabled_default,
      '#description' => $this->t('Selected bundles will have Bookshop ISBN lookups, link injection, and widgets available. Field mappings can be customised below.'),
    ];

    $field_options_cache = [];
    foreach ($bundles as $bundle_id => $bundle) {
      $label = $bundle['label'] ?? $bundle_id;
      $settings = $bundle_settings[$bundle_id] ?? [];

      $fields = $this->fieldManager->getFieldDefinitions('node', $bundle_id);
      $field_options = [];
      foreach ($fields as $field_name => $definition) {
        // Only expose fields that can hold text or entity references that might
        // be useful. We allow everything but skip computed fields.
        if ($definition->isComputed()) {
          continue;
        }
        $field_options[$field_name] = $definition->getLabel() . ' (' . $field_name . ')';
      }
      asort($field_options, SORT_NATURAL | SORT_FLAG_CASE);

      if ($debug_logging) {
        \Drupal::logger('wlt_bookshop_settings')->notice('Bundle @bundle field options: @options', [
          '@bundle' => $bundle_id,
          '@options' => implode(', ', array_keys($field_options)),
        ]);
      }

      $field_options_cache[$bundle_id] = $field_options;

      $form['bundle_' . $bundle_id] = [
        '#type' => 'details',
        '#title' => $this->t('@label configuration', ['@label' => $label]),
        '#open' => in_array($bundle_id, $enabled_default, TRUE),
        '#tree' => TRUE,
        '#states' => [
          'visible' => [
            ':input[name="enabled_bundles[' . $bundle_id . ']"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['bundle_' . $bundle_id]['author_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Author field'),
        '#options' => $field_options,
        '#default_value' => $settings['author_field'] ?? '',
        '#required' => FALSE,
        '#empty_option' => $this->t('- None -'),
        '#description' => $this->t('Field providing author information used for ISBN lookups. Required when the bundle is enabled.'),
      ];
      $form['bundle_' . $bundle_id]['isbn_field'] = [
        '#type' => 'select',
        '#title' => $this->t('ISBN field'),
        '#options' => $field_options,
        '#default_value' => $settings['isbn_field'] ?? '',
        '#required' => FALSE,
        '#empty_option' => $this->t('- None -'),
        '#description' => $this->t('Field that will store discovered ISBN values. Required when the bundle is enabled.'),
      ];
      $form['bundle_' . $bundle_id]['editor_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Editor field'),
        '#options' => ['' => $this->t('- None -')] + $field_options,
        '#default_value' => $settings['editor_field'] ?? '',
        '#required' => FALSE,
        '#description' => $this->t('Optional field whose values will also be linkified inside node content.'),
      ];
      $form['bundle_' . $bundle_id]['kill_switch_field'] = [
        '#type' => 'select',
        '#title' => $this->t('Kill switch field'),
        '#options' => ['' => $this->t('- None -')] + $field_options,
        '#default_value' => $settings['kill_switch_field'] ?? '',
        '#required' => FALSE,
        '#description' => $this->t('Optional boolean field to disable ISBN lookups and link injection on specific nodes.'),
      ];
      $form['bundle_' . $bundle_id]['bypass_uid_check'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Allow widget rendering for all users'),
        '#default_value' => !empty($settings['bypass_uid_check']),
        '#description' => $this->t('When enabled the Bookshop widget renders regardless of the viewer user ID.'),
      ];
    }

    // Cache field options for submit processing.
    $form_state->set('wlt_bookshop_field_options', $field_options_cache);

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $enabled = array_filter($form_state->getValue('enabled_bundles') ?? []);
    $field_options_cache = $form_state->get('wlt_bookshop_field_options') ?? [];

    $debug_logging = (bool) ($this->configFactory->get('wlt_bookshop.settings')->get('debug_logging') ?? FALSE);
    if ($debug_logging) {
      \Drupal::logger('wlt_bookshop_settings')->notice('Enabled bundles submitted: @bundles', [
        '@bundles' => implode(', ', array_keys($enabled)),
      ]);
    }

    foreach ($enabled as $bundle_id => $bundle_value) {
      $author = (string) $form_state->getValue(['bundle_' . $bundle_id, 'author_field']);
      $isbn = (string) $form_state->getValue(['bundle_' . $bundle_id, 'isbn_field']);
      $editor = (string) $form_state->getValue(['bundle_' . $bundle_id, 'editor_field']);
      $kill = (string) $form_state->getValue(['bundle_' . $bundle_id, 'kill_switch_field']);

      $options = $field_options_cache[$bundle_id] ?? [];

      if ($debug_logging) {
        \Drupal::logger('wlt_bookshop_settings')->notice('Validating bundle @bundle: author=@a isbn=@i editor=@e kill=@k options=[@options]', [
          '@bundle' => $bundle_id,
          '@a' => $author,
          '@i' => $isbn,
          '@e' => $editor,
          '@k' => $kill,
          '@options' => implode(', ', array_keys($options)),
        ]);
      }

      if ($author === '' || $isbn === '') {
        $form_state->setErrorByName('bundle_' . $bundle_id . '][author_field', $this->t('Author and ISBN fields are required for the @bundle bundle.', ['@bundle' => $bundle_id]));
        continue;
      }
      if (!isset($options[$author])) {
        $form_state->setErrorByName('bundle_' . $bundle_id . '][author_field', $this->t('The selected author field is not available on the @bundle bundle.', ['@bundle' => $bundle_id]));
      }
      if (!isset($options[$isbn])) {
        $form_state->setErrorByName('bundle_' . $bundle_id . '][isbn_field', $this->t('The selected ISBN field is not available on the @bundle bundle.', ['@bundle' => $bundle_id]));
      }
      if ($editor !== '' && !isset($options[$editor])) {
        $form_state->setErrorByName('bundle_' . $bundle_id . '][editor_field', $this->t('The selected editor field is not available on the @bundle bundle.', ['@bundle' => $bundle_id]));
      }
      if ($kill !== '' && !isset($options[$kill])) {
        $form_state->setErrorByName('bundle_' . $bundle_id . '][kill_switch_field', $this->t('The selected kill switch field is not available on the @bundle bundle.', ['@bundle' => $bundle_id]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $enabled = array_filter($form_state->getValue('enabled_bundles') ?? []);
    $bundle_settings = [];

    foreach ($enabled as $bundle_id => $value) {
      $bundle_settings[$bundle_id] = [
        'author_field' => $form_state->getValue(['bundle_' . $bundle_id, 'author_field']) ?: '',
        'isbn_field' => $form_state->getValue(['bundle_' . $bundle_id, 'isbn_field']) ?: '',
        'editor_field' => $form_state->getValue(['bundle_' . $bundle_id, 'editor_field']) ?: '',
        'kill_switch_field' => $form_state->getValue(['bundle_' . $bundle_id, 'kill_switch_field']) ?: '',
        'bypass_uid_check' => (bool) $form_state->getValue(['bundle_' . $bundle_id, 'bypass_uid_check']),
      ];
    }

    $this->configFactory->getEditable('wlt_bookshop.settings')
      ->set('bundle_settings', $bundle_settings)
      ->set('debug_logging', (bool) $form_state->getValue('debug_logging'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
