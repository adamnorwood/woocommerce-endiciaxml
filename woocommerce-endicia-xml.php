<?php
/**
  * Plugin Name: WooCommerce Endicia XML
  * Plugin URI: https://github.com/adamnorwood/woocommerce-endicia-xml
  * Description: An attempt to integrate WooCommerce with the Endicia DAZzle XML functionality
  * Version: 0.1a
  * Author: Adam Norwood
  * Author URI: http://adamnorwood.com/
  *
  * @class WC_Endicia
  * @package WooCommerce
  * @category Integrations
  * @author Adam Norwood
  *
  * Copyright 2012 Adam Norwood (email : adam@adamnorwood.com)
  *
  * This program is free software; you can redistribute it and/or modify
  * it under the terms of the GNU General Public License, version 2, as
  * published by the Free Software Foundation.
  *
  * This program is distributed in the hope that it will be useful,
  * but WITHOUT ANY WARRANTY; without even the implied warranty of
  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  * GNU General Public License for more details.
  *
  * You should have received a copy of the GNU General Public License
  * along with this program; if not, write to the Free Software
  * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
  */

// Make sure WooCommerce is installed and active first, then get started
if ( is_admin() && in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

  // Why is this class wrapped up in a function wrapper (it's called at the end on the plugin_loaded action hook)?
  // Because otherwise our class fails to find the other WooCommerce classes, as they haven't loaded yet...
  add_action( 'plugins_loaded', 'wc_endicia_xml_init' );

  function wc_endicia_xml_init() {

    // We'll be hooking into the WC_Integration class for functionality
    if ( !class_exists('WC_Integration') ) {
      require( ABSPATH . 'wp-content/plugins/woocommerce/classes/integrations/class-wc-integration.php' ); exit;
    }

    class WC_Endicia_XML extends WC_Integration {

      // Holds order info in single order views
      var $order = null;

      function __construct() {

        $this->id                 = 'endicia_xml';
        $this->method_title       = __( 'Endicia XML', 'woocommerce' );
        $this->method_description = __( 'Configure these settings to enable exporting orders to Endicia / DAZzle XML for shipping', 'woocommerce' );

        // Load the settings page form fields
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();
        # echo '<pre>'; print_r($this->settings); echo '</pre>'; exit;

        // Save our settings
        add_action( 'woocommerce_update_options_integration_endicia_xml', array( &$this, 'process_admin_options' ) );

        // Add the Generate Postage button to the Order dashboard
        add_action( 'woocommerce_order_actions', array( $this, 'add_button_generate_postage_xml' ) );

        // Set up our POST handler that will fire off the Endicia XML download
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'handle_post' ) );

      } // ends __construct()


      /**
       * Initialise settings page form fields (on the Integrations tab)
       */
      function init_form_fields() {

        // Expand our allowed image formats into options for a <select> menu
        $this->form_fields = array(
          'endicia_xml_output_file' => array(
            'title'       => __('Output File', 'woocommerce'),
            'label'       => __('Directory path and filename to be used for the output Endicia XML file', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_output_file') ? get_option('woocommerce_endicia_xml_xml_output_file') : 'C:\Documents and Settings\Administrator\My Documents\Endicia\DAZzle\xml\woocommerce-endicia.xml'
          ),

          'endicia_xml_testing_mode' => array(
            'title'       => __('Testing Mode', 'woocommerce'),
            'label'       => __('Enable testing mode (i.e. do not request live postage)', 'woocommerce'),
            'type'        => 'checkbox',
            'default'     => get_option('woocommerce_endicia_xml_testing_mode') ? get_option('woocommerce_endicia_xml_testing_mode') : 'no' // backwards
          ),

          'endicia_xml_start' => array(
            'title'       => __('Immediate Print', 'woocommerce'),
            'label'       => __('If checked, the postage will be sent to the printer immediately after DAZzle imports the XML', 'woocommerce'),
            'type'        => 'checkbox',
            'default'     => get_option('woocommerce_endicia_xml_start') ? get_option('woocommerce_endicia_xml_start') : 'no' // backwards
          ),

          'endicia_xml_stealth' => array(
            'title'       => __('Stealth Mode', 'woocommerce'),
            'label'       => __('Enable stealth mode (i.e. do not show shipping rate on the label)', 'woocommerce'),
            'type'        => 'checkbox',
            'default'     => get_option('woocommerce_endicia_xml_stealth') ? get_option('woocommerce_endicia_xml_stealth') : 'no' // backwards
          ),

          'endicia_xml_insured_mail' => array(
            'title'       => __('Insured Mail', 'woocommerce'),
            'label'       => __('Choose to insure your shipments', 'woocommerce'),
            'type'        => 'checkbox',
            'default'     => get_option('woocommerce_endicia_xml_insured_mail') ? get_option('woocommerce_endicia_xml_insured_mail') : 'no' // backwards
          ),

          'endicia_xml_signature_confirmation' => array(
            'title'       => __('Signature Confirmation', 'woocommerce'),
            'label'       => __('Require the customer&#8217;s signature on delivery', 'woocommerce'),
            'type'        => 'checkbox',
            'default'     => get_option('woocommerce_endicia_xml_signature_confirmation') ? get_option('woocommerce_endicia_xml_signature_confirmation') : 'no' // backwards
          )

        );

      } // End init_form_fields()


      /**
       * Adds the "Generate Postage" button to the single Order view in the admin dashboard
       */
      function add_button_generate_postage_xml() {

        echo '<li><input type="submit" class="button tips" name="endicia_generate_xml" value="Download Endicia XML" data-tip="Click this button to generate XML shipping info file for Endicia / DAZzle" /></li>';

      } // ends add_button_generate_postage_xml()


      /**
       * Handles the $_POST data and fires off the request for postage when the Generate Postage button has been clicked
       */
      function handle_post() {

        if ((isset($_POST['endicia_generate_xml'])) && ($_POST['endicia_generate_xml'] != '')) {

          // Simplify our settings variables
          $settings = $this->settings;

          // Validate output file location
          $outputFile = '';
          if (isset($settings['endicia_xml_output_file']) || array_key_exists('endicia_xml_output_file', $settings)) {
            $outputFile = $settings['endicia_xml_output_file'];
          }

          // Validate testing mode YES|NO
          $testing_mode = 'YES';
          if (isset($settings['endicia_xml_testing_mode']) || array_key_exists('endicia_xml_testing_mode',$settings)) {
            $testing_mode = strtoupper($settings['endicia_xml_testing_mode']);
            $testing_mode = (($testing_mode == 'YES') || ($testing_mode == 'NO')) ? $testing_mode : 'YES';
          }

          // Validate start="PRINTING" YES|NO
          $immediatePrint = 'NO';
          if (isset($settings['endicia_xml_start']) || array_key_exists('endicia_xml_start', $settings)) {
            $immediatePrint = strtoupper($settings['endicia_xml_start']);
            $immediatePrint = ($immediatePrint == 'YES') ? 'PRINTING' : 'NO';
          }

          // Validate stealth mode
          $stealth = 'FALSE';
          if (isset($settings['endicia_xml_stealth']) || array_key_exists('endicia_xml_stealth',$settings)) {
            $stealth = strtoupper($settings['endicia_xml_stealth']);
            $stealth  = ($stealth == 'YES') ? 'TRUE' : 'FALSE';
          }

          // Validate insured mail
          $insured = 'OFF';
          if (isset($settings['endicia_xml_insured_mail']) || array_key_exists('endicia_xml_insured_mail',$settings)) {
            $insured = strtoupper($settings['endicia_xml_insured_mail']);
            $insured = ($insured == 'YES') ? 'ON' : 'OFF';
          }

          // Validate signature confirmation
          $signatureConfirmation = 'OFF';
          if (isset($settings['endicia_xml_signature_confirmation']) || array_key_exists('endicia_xml_signature_confirmation',$settings)) {
            $signatureConfirmation = strtoupper($settings['endicia_xml_signature_confirmation']);
            $signatureConfirmation = ($signatureConfirmation) ? 'ON' : 'OFF';
          }

          // Validate email address
          $toEmail = '';
          if (isset($_POST['_billing_email']) || array_key_exists('_billing_email', $_POST)) {
            $toEmail = filter_var($_POST['_billing_email'], FILTER_SANITIZE_EMAIL);
          }

          // Validate phone numbers (must not include punctuation)
          $toPhone = '';
          if (isset($_POST['_billing_phone']) || array_key_exists('_billing_phone',$_POST)) {
            $toPhone = str_replace( array( ' ','(',')','-','+','.'), '', $_POST['_billing_phone'] );
          }

          $output = <<< END
<DAZzle OutputFile='{$outputFile}' Start='{$immediatePrint}' Test='YES' Prompt='YES' AutoClose='NO'>
  <Package ID='1'>
    <MailClass>LIBRARYMAIL</MailClass>
    <PackageType>FLATRATEBOX</PackageType>
    <WeightOz>5</WeightOz>
    <Value>{$_POST['_order_total']}</Value>
    <Description>Bleep Labs Order #{$_POST['post_ID']}</Description>
    <ReferenceID>{$_POST['post_ID']}</ReferenceID>
    <ToName>{$_POST['_shipping_first_name']} {$_POST['_shipping_last_name']}</ToName>
    <ToAddress1>{$_POST['_shipping_address_1']}</ToAddress1>
    <ToAddress2>{$_POST['_shipping_address_2']}</ToAddress2>
    <ToCity>{$_POST['_shipping_city']}</ToCity>
    <ToState>{$_POST['_shipping_state']}</ToState>
    <ToPostalCode>{$_POST['_shipping_postcode']}</ToPostalCode>
    <ToEMail>{$toEmail}</ToEMail>
    <ToPhone>{$toPhone}</ToPhone>
  </Package>
</DAZzle>
END;

          // Download the file!
          header( 'Content-type: application/xml' );
          header( 'Content-Disposition: attachment; filename=output.xml');
          header( 'Pragma: no-cache');
          header( 'Expires: 0');
          echo $output; exit;

        }
      } // ends handle_post()
    } // ends WC_Endicia_XML

    // Add Endicia option to the Integrations settings panel
    function add_endicia_xml_integration( $integrations ) {
      $integrations[] = 'WC_Endicia_XML'; return $integrations;
    }
    add_filter('woocommerce_integrations', 'add_endicia_xml_integration' );

  } // closes wc_endicia_init

}