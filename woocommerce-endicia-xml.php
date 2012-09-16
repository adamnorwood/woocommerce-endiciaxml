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

// Make sure WooCommerce is installed and active, then get started
if ( is_admin() && in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

  // Why is this class wrapped up in a function wrapper (it's called at the end on the plugin_loaded action hook)?
  // Because otherwise our class fails to find the other WooCommerce classes, as they haven't loaded yet...
  add_action( 'plugins_loaded', 'wc_endicia_xml_init', 200 );

  function wc_endicia_xml_init() {

    // We'll be hooking into a couple of WC classes for functionality
    if ( !class_exists('WC_Integration') ) {
      require( ABSPATH . 'wp-content/plugins/woocommerce/classes/integrations/class-wc-integration.php' ); exit;
    }

    if ( !class_exists('WC_Product_Variation') ) {
      require( ABSPATH . 'wp-content/plugins/woocommerce/classes/class-wc-product-variation.php' );
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

        // Save our settings
        add_action( 'woocommerce_update_options_integration_endicia_xml', array( &$this, 'process_admin_options' ) );

        // Add the Endicia XML section to the Order dashboard
        add_action( 'woocommerce_admin_order_totals_after_shipping', array( $this, 'add_endicia_xml_panel' ) );

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
       * Adds the "Download Endicia XML" button and other Endicia content to the single Order view in the admin dashboard
       */
      function add_endicia_xml_panel() {

        echo <<< END

          <div class="clear"></div>
        </div>
        <div class="totals_group">
          <h4>Endicia XML</h4>
          <ul class="totals">
            {$this->add_weight_info()}
            <li class="wide">
              <label for="endicia-mail-class">Mail class:</label>
              <select name="endicia_mail_class" id="endicia-mail-class">
                <option value="FIRST">First-Class Mail</option>
                <option value="PRIORITY">Priority Mail</option>
                <option value="PARCELPOST">Parcel Post</option>
                <option value="MEDIAMAIL">Media Mail</option>
                <option value="LIBRARYMAIL">Library Mail</option>
                <option value="BOUNDPRINTEDMATTER">Bound Printed Matter</option>
                <option value="EXPRESS">Express Mail</option>
                <option value="PRESORTEDFIRST">Presorted, First Class</option>
                <option value="PRESORTEDSTANDARD">Presorted, Standard Mail</option>
                <option value="INTLFIRST">First-Class Mail International</option>
                <option value="INTLEXPRESS">Express Mail International</option>
                <option value="INTLPRIORITY">Priority Mail International</option>
                <option value="INTLGXG">Global Express Guaranteed</option>
                <option value="INTLGXGNODOC">Global Express Guaranteed Non-Documents</option>
                <option value="PARCELSELECT">Parcel Select</option>
                <option value="CRITICALMAIL">Critical Mail</option>
              </select>
            </li>
            <li class="wide">
              <label for="endicial-package-type">Package type:</label>
              <select name="endicia_package_type" id="endicia-package-type">
                <option value="FLATRATEENVELOPE">Flat Rate Envelope</option>
                <option value="FLATRATEBOX">Flat Rate Box</option>
                <option value="FLATRATEMEDIUMBOX">Flat Rate Medium Box</option>
                <option value="FLATRATELARGEBOX">Flat Rate Large Box</option>
                <option value="FLATRATESMALLBOX">Flat Rate Small Box</option>
                <option value="FLATRATEPADDEDENVELOPE">Flat Rate Padded Envelope</option>
                <option value="FLATRATELEGALENVELOPE">Flat Rate Legal Envelope</option>
                <option value="RECTPARCEL">Rectangular Parcel</option>
                <option value="NONRECTPARCEL">Non-rectangular Parcel</option>
                <option value="POSTCARD">Postcard</option>
                <option value="ENVELOPE">First-Class Mail Letter</option>
                <option value="FLAT">First-Class Mail Large Envelope</option>
                <option value="REGIONALRATEBOXA">Regional Rate &#8212; Box A</option>
                <option value="REGIONALRATEBOXB">Regional Rate &#8212; Box B</option>
                <option value="REGIONALRATEBOXC">Regional Rate &#8212; Box C</option>
                <option value="FLATRATEGIFTCARDNVELOPE">Flat Rate Gift Card Envelope</option>
                <option value="FLATRATEWINDOWENVELOPE">Flat Rate Window Envelope</option>
                <option value="FLATRATECARDBOARDENVELOPE">Flat Rate Cardboard Envelope</option>
                <option value="SMALLFLATRATEENVELOPE">Small Flat Rate Envelope</option>
                <option value="FLATRATEDVDBOX">Flat Rate DVD Box</option>
                <option value="FLATRATELARGEVIDEOBOX">Flat Rate Large Video Box</option>
                <option value="FLATRATELARGEBOARDGAMEBOX">Flat Rate Large Board Game Box</option>
                <option value="HALFTRAYBOX">Half Tray Box (for PMOD)</option>
                <option value="FULLTRAYBOX">Full Tray Box (for PMOD)</option>
                <option value="EMMTRAYBOX">EMM Tray Box (for PMOD)</option>
                <option value="FLATTUBTRAYBOX">Flat Tub Tray Box (for PMOD)</option>
                <option value="SACK">Sack (for PMOD and EMOD)</option>
              </select>
            </li>
            <li class="left">
              <input type="submit" class="button tips" name="endicia_generate_xml" value="Download Endicia XML" data-tip="Click this button to generate XML shipping info file for Endicia / DAZzle" />
            </li>
          </ul>
        <div class="clear"></div>
        </div>
        <div>
END;
      } // ends add_button_generate_postage_xml()


      /**
       * Adds the "Total Order Weight" input to the order display page, critical info for the Endicia postage
       */
      function add_weight_info() {

        $totalWeight = 0;
        $weightUnit  = get_option('woocommerce_weight_unit');

        // Query information about our order, based on the ID in the query string
        $orderID  = ( isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) ? $_GET['post'] : null;
        $this->order = ( $orderID != null) ? new WC_Order( $orderID ) : null;

        if ( $this->order != null ) {

          // WC_Order has the list of items for this order...
          $items = unserialize( $this->order->order_custom_fields['_order_items'][0] );

          if ( is_array( $items ) ) {

            foreach ( $items as $item ) {

              // And then WC_Product_Variation can leak to us the weight of the item!
              $product = new WC_Product_Variation( $item['id'] );
              $totalWeight += $product->weight;

            }

          }

        }

        $output = <<< END
          <li class="wide">
            <label for="order-weight">Total Order Weight (in {$weightUnit}):</label>
            <input type="text" name="order-weight" id="order-weight" value="{$totalWeight}" />
          </li>
END;

        return $output;

      } // ends add_weight_info()



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

          // Translate the weight of the order into ounces. The XML format requires oz,
          // expressed as float with one decimal place
          $weight = ( $_POST['order-weight'] != '') ? round( woocommerce_get_weight( $_POST['order-weight'], 'oz'), 1 ) : 0;

          // Grab the Mail Class and Package Type from the dropdowns
          $mailClass   = ( $_POST['endicia_mail_class']   != '')  ? $_POST['endicia_mail_class']   : '';
          $packageType = ( $_POST['endicia_package_type'] != '' ) ? $_POST['endicia_package_type'] : '';

          $output = <<< END
<DAZzle OutputFile='{$outputFile}' Start='{$immediatePrint}' Test='YES' Prompt='YES' AutoClose='NO'>
  <Package ID='1'>
    <MailClass>{$mailClass}</MailClass>
    <PackageType>{$packageType}</PackageType>
    <WeightOz>{$weight}</WeightOz>
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