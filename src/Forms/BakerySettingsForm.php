<?php

namespace Drupal\bakery\Forms;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure bakery settings for this site.
 */
class BakerySettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bakery_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'bakery.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('bakery.settings');

    //print "<pre>";
    //print_r(openssl_get_cipher_methods());
    //exit();
    
    $form['bakery_is_main'] = array(
      '#type' => 'checkbox',
      '#title' => 'Is this the main site?',
      '#default_value' => $config->get('bakery_is_main'),
      '#description' => t('On the main site, accounts need to be created by traditional processes, i.e by a user registering or an admin creating them.'),
    );

    $form['bakery_main'] = array(
      '#type' => 'textfield',
      '#title' => 'Main site URL',
      '#default_value' => $config->get('bakery_main'),
      '#description' => t('Specify the main site for your bakery network.'),
    );

    $bakery_minions = $config->get('bakery_minions');
    $form['bakery_minions'] = array(
      '#type' => 'textarea',
      '#title' => 'Minion sites',
      '#default_value' => $bakery_minions,
      '#description' => t('Specify any minion sites in your bakery network that you want to update if a user changes email or username on the main site. Enter one site per line, in the form "http://sub.example.com/".'),
    );

    $form['bakery_help_text'] = array(
      '#type' => 'textarea',
      '#title' => 'Help text for users with synch problems.',
      '#default_value' => $config->get('bakery_help_text'),
      '#description' => t('This message will be shown to users if/when they have problems synching their accounts. It is an alternative to the "self repair" option and can be blank.'),
    );

    $form['bakery_freshness'] = array(
      '#type' => 'textfield',
      '#title' => 'Seconds of age before a cookie is old',
      '#default_value' => $config->get('bakery_freshness'),
    );

    $form['bakery_key'] = array(
      '#type' => 'textfield',
      '#title' => 'Private key for cookie validation',
      '#default_value' => $config->get('bakery_key'),
    );

    $form['bakery_domain'] = array(
      '#type' => 'textfield',
      '#title' => 'Cookie domain',
      '#default_value' => $config->get('bakery_domain'),
    );

    $default = $config->get('bakery_supported_fields');
    $default['mail'] = 'mail';
    $default['name'] = 'name';
    $options = array(
      'name' => t('username'),
      'mail' => t('e-mail'),
      'status' => t('status'),
      'picture' => t('user picture'),
      'language' => t('language'),
      'signature' => t('signature'),
    );
    // TODO: need to add profile fileds
    /*
    if (module_exists('profile')) {
    $result = db_query('SELECT name, title FROM {profile_field}
    ORDER BY category, weight');
    foreach ($result as $field) {
    $options[$field->name] = check_plain($field->title);
    }
    }
     */
    $form['bakery_supported_fields'] = array(
      '#type' => 'checkboxes',
      '#title' => 'Supported profile fields',
      '#default_value' => $default,
      '#options' => $options,
      '#description' => t('Choose the profile fields that should be exported by the main site and imported on the minions. Username and E-mail are always exported. The correct export of individual fields may depend on the appropriate settings for other modules on both main and slaves. You need to configure this setting on both the main site and the minions.'),
    );
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('bakery.settings')
      ->set('bakery_is_main', $values['bakery_is_main'])
      ->set('bakery_main', $form_state->getValue('bakery_main'))
      ->set('bakery_help_text', $form_state->getValue('bakery_help_text'))
      ->set('bakery_freshness', $form_state->getValue('bakery_freshness'))
      ->set('bakery_key', $form_state->getValue('bakery_key'))
      ->set('bakery_domain', $form_state->getValue('bakery_domain'))
      ->set('bakery_supported_fields', $form_state->getValue('bakery_supported_fields'))
      ->set('bakery_minions', $form_state->getValue('bakery_minions'))
      ->save();
    parent::submitForm($form, $form_state);
  }
}
