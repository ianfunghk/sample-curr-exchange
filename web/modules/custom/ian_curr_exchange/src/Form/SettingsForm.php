<?php

namespace Drupal\ian_curr_exchange\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure ian_curr_exchange settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ian_curr_exchange_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ian_curr_exchange.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['access_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Access Key'),
      '#default_value' => $this->config('ian_curr_exchange.settings')->get('access_key'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (empty($form_state->getValue('access_key'))) {
      $form_state->setErrorByName('access_key', $this->t('The value cannot be empty.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ian_curr_exchange.settings')
      ->set('access_key', $form_state->getValue('access_key'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
