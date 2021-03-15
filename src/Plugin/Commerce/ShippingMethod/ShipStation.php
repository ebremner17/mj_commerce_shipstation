<?php

namespace Drupal\mj_commerce_shipstation\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_canadapost\Api\RatingServiceInterface;
use Drupal\commerce_canadapost\UtilitiesService;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;

use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\Core\Form\FormStateInterface;

use Drupal\state_machine\WorkflowManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use CanadaPost\Rating;

/**
 * Provides the Canada Post shipping method.
 *
 * @CommerceShippingMethod(
 *  id = "shipstation",
 *  label = @Translation("Ship Station"),
 *  services = {
 *    "DOM.EP" = @Translation("Expedited Parcel - Canada"),
 *    "DOM.RP" = @Translation("Regular Parcel - Canada"),
 *    "USA.XP" = @Translation("Xpresspost - USA"),
 *    "Free" = @Translation("Free shipping"),
 *   }
 * )
 */
class ShipStation extends ShippingMethodBase {

  /**
   * The package type manager.
   *
   * @var \Drupal\commerce_shipping\PackageTypeManagerInterface
   */
  protected $packageTypeManager;

  /**
   * The workflow manager.
   *
   * @var \Drupal\state_machine\WorkflowManagerInterface
   */
  protected $workflowManager;

  /**
   * The shipping services.
   *
   * @var \Drupal\commerce_shipping\ShippingService[]
   */
  protected $services = [];

  /**
   * The parent config entity.
   *
   * Not available while the plugin is being configured.
   *
   * @var \Drupal\commerce_shipping\Entity\ShippingMethodInterface
   */
  protected $parentEntity;

  /**
   * Constructs a new ShippingMethodBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_shipping\PackageTypeManagerInterface $package_type_manager
   *   The package type manager.
   * @param \Drupal\state_machine\WorkflowManagerInterface $workflow_manager
   *   The workflow manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, PackageTypeManagerInterface $package_type_manager, WorkflowManagerInterface $workflow_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $package_type_manager, $workflow_manager);

    $this->packageTypeManager = $package_type_manager;
    $this->workflowManager = $workflow_manager;
    foreach ($this->pluginDefinition['services'] as $id => $label) {
      $this->services[$id] = new ShippingService($id, (string) $label);
    }
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.commerce_package_type'),
      $container->get('plugin.manager.workflow')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'api' => [
          'customer_number' => '',
          'username' => '',
          'password' => '',
          'contract_id' => '',
          'mode' => 'test',
          'log' => [],
        ],
        'shipping_information' => [
          'origin_postal_code' => '',
          'option_codes' => [],
        ],
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // The details tab for the API information.
    $form['api_information'] = [
      '#type' => 'details',
      '#title' => $this->t('API Information'),
      '#open' => TRUE,
    ];

    // The API key for Ship Station.
    $form['api_information']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Enter your Ship Station API key'),
      '#default_value' => $this->configuration['api_key'] ?? '',
      '#required' => TRUE,
    ];

    // The API secret for Ship Station.
    $form['api_information']['api_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API secret'),
      '#description' => $this->t('Enter your Ship Station API secret'),
      '#default_value' => $this->configuration['api_secret'] ?? '',
      '#required' => TRUE,
    ];

    // The details tab for the shipping information.
    $form['shipping_information'] = [
      '#type' => 'details',
      '#title' => $this->t('Shipping rate modifications'),
      '#open' => TRUE,
    ];

    $form['shipping_information']['origin_postal_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Origin postal code'),
      '#default_value' => $this->configuration['shipping_information']['origin_postal_code'],
      '#description' => $this->t("Enter the postal code that your shipping rates will originate. If left empty, shipping rates will be rated from your store's postal code."),
      '#required' => TRUE,
    ];


    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {

    parent::validateConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {

    // Get the values from the parent.
    $values = $form_state->getValue($form['#parents']);

    // Save the config for the shipping.
    $this->configuration['api_key'] = $values['api_information']['api_key'];
    $this->configuration['api_secret'] = $values['api_information']['api_secret'];
    $this->configuration['shipping_information']['origin_postal_code'] = $values['shipping_information']['origin_postal_code'];

    return parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * Determine if we have the minimum information to connect to Canada Post.
   *
   * @return bool
   *   TRUE if there is enough information to connect, FALSE otherwise.
   */
  public function apiIsConfigured() {
    $api_information = $this->configuration['api'];

    return (
      !empty($api_information['username'])
      && !empty($api_information['password'])
      && !empty($api_information['customer_number'])
      && !empty($api_information['mode'])
    );
  }

  /**
   * Calculates rates for the given shipment.
   *
   * @param \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment
   *   The shipment.
   *
   * @return \Drupal\commerce_shipping\ShippingRate[]
   *   The rates.
   */
  public function calculateRates(ShipmentInterface $shipment) {

    // The array for the rates.
    $rates = [];

    // If there is no address just return a blank rates array.
    if ($shipment->getShippingProfile()->get('address')->isEmpty()) {
      return $rates;
    }

    // Get the country code from the shipment object.
    $country_code = $shipment->getShippingProfile()->get('address')->getValue()[0]['country_code'];

    // Get the shipping methods, so that we can get shipping method id.
    $shipping_method_storage = \Drupal::entityTypeManager()->getStorage('commerce_shipping_method');
    $shipping_methods = $shipping_method_storage->loadMultiple();

    // Get the total price.
    $total_price = $shipment->getOrder()->getTotalPrice()->getNumber();

    // Step through all the shipping methods, all we want is the
    // first one, which will give us the correct shipping method id.
    foreach ($shipping_methods as $shipping_method_id => $sm) {
      break;
    }

    // Step through each of the services (types of shipping) and
    // set the rates array.
    foreach ($this->services as $key => $service) {

      // Flag to tell if we use this rate, dependent on country.
      $insert_rate = FALSE;

      // Set the price based on the key.
      // DOM.RP - Canada Regular Parcel.
      // DOM.XP - Canada Express Parcel.
      // USA.EP - USA Express Parcel.
      switch ($key) {
        case 'DOM.RP':
          $price = new Price('12','CAD');

          if ($country_code == 'CA' && (int) $total_price <= 100) {
            $insert_rate = TRUE;
          }
          break;

        case 'DOM.EP':
          $price = new Price('18','CAD');

          if ($country_code == 'CA' && (int) $total_price <= 100) {
            $insert_rate = TRUE;
          }

          break;

        case 'USA.XP':
          $price = new Price('20','CAD');

          if ($country_code == 'US' && (int) $total_price <= 100) {
            $insert_rate = TRUE;
          }
          break;

        case "Free":
          $price = new Price('0','CAD');

          if ((int) $total_price > 100) {
            $insert_rate = TRUE;
          }
          break;
      }

      // Setup the definition for the shipping rates.
      $definition = [
        'shipping_method_id' => $shipping_method_id,
        'service' => $this->services[$key],
        'amount' => $price,
      ];

      // Insert in the rates array if we are in the
      // correct country.
      if ($insert_rate) {
        $rates[] = new ShippingRate($definition);
      }
    }

    // Return the rates.
    return $rates;
  }

}
