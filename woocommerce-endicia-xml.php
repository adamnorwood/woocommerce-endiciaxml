<?php
/**
  * Plugin Name: WooCommerce Endicia XML
  * Plugin URI: https://github.com/adamnorwood/woocommerce-endicia-xml
  * Description: An attempt to integrate WooCommerce with the Endicia DAZzle XML functionality
  * Version: 0.9b
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
  add_action( 'plugins_loaded', 'wc_endicia_xml_init' );

  function wc_endicia_xml_init() {

    // We'll be hooking into a couple of WC classes for functionality
    if ( !class_exists('WC_Integration') ) {
      require( ABSPATH . 'wp-content/plugins/woocommerce/classes/integrations/class-wc-integration.php' ); exit;
    }

    if ( !class_exists('WC_Product_Variation') ) {
      require( ABSPATH . 'wp-content/plugins/woocommerce/classes/class-wc-product-variation.php' );
    }

    if ( !class_exists('WC_Countries') ) {
      require( ABSPATH . 'wp-content/plugins/woocommerce/classes/class-wc-countries.php' );
    }

    class WC_Endicia_XML extends WC_Integration {

      // Holds order info in single order views
      var $order = null;

      function __construct() {

        $this->id                 = 'endicia_xml';
        $this->method_title       = __( 'Endicia XML', 'woocommerce' );
        $this->method_description = __( 'Endicia XML generates XML files per-order for use with Endicia DAZzle shipping label printing', 'woocommerce' );

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
          'endicia_xml_directory' => array(
            'title'       => __('DAZzle document folder path', 'woocommerce'),
            'description' => __('Full directory path where the DAZzle .lyt layout files etc. are stored', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_directory') ? get_option('woocommerce_endicia_xml_xml_directory') : 'C:\Documents and Settings\Administrator\My Documents\Endicia\DAZzle\\'
          ),

          'endicia_xml_output_file' => array(
            'title'       => __('Output XML File', 'woocommerce'),
            'description' => __('Filename to be used for the output Endicia XML file', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_output_file') ? get_option('woocommerce_endicia_xml_xml_output_file') : 'xml\woocommerce-endicia.xml'
          ),

          'endicia_xml_testing_mode' => array(
            'title'       => __('Testing Mode', 'woocommerce'),
            'label'       => __('Enable testing mode (i.e. do not request live postage)', 'woocommerce'),
            'type'        => 'checkbox',
            'default'     => get_option('woocommerce_endicia_xml_testing_mode') ? get_option('woocommerce_endicia_xml_testing_mode') : 'no' // backwards
          ),

          'endicia_xml_prompt' => array(
            'title'       => __('Prompt', 'woocommerce'),
            'label'       => __('If unchecked, DAZzle will suppress all printing windows and info boxes', 'woocommerce'),
            'type'        => 'checkbox',
            'default'     => get_option('woocommerce_endicia_xml_prompt') ? get_option('woocommerce_endicia_xml_prompt') : 'no' // backwards
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

          'endicia_xml_auto_print_customs_forms' => array(
            'title'       => __('Auto-Print Customs Forms?', 'woocommerce'),
            'label'       => __('If checked, customs forms will automatically print without confirmation', 'woocommerce'),
            'type'        => 'checkbox',
            'default'     => get_option('woocommerce_endicia_xml_auto_print_customs_forms') ? get_option('woocommerce_endicia_xml_auto_print_customs_forms') : '' // backwards
          ),

          'endicia_xml_customs_certify' => array(
            'title'       => __('Customs Certify', 'woocommerce'),
            'label'       => __('Enable Customs Certify mode (i.e. use a printed name in place of a signature on the customs form)', 'woocommerce'),
            'type'        => 'checkbox',
            'default'     => get_option('woocommerce_endicia_xml_customs_certify') ? get_option('woocommerce_endicia_xml_customs_certify') : '' // backwards
          ),

          'endicia_xml_customs_signer' => array(
            'title'       => __('Customs Signer', 'woocommerce'),
            'description' => __('The name to be printed in place of a signature if Customs Certify is enabled', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_customs_signer') ? get_option('woocommerce_endicia_xml_customs_signer') : '' // backwards
          ),

          'endicia_xml_customs_hts1' => array(
            'title'       => __('Customs HTS (default)', 'woocommerce'),
            'description' => __('Your desired default Harmonized Tariff Schedule ID for customs declarations', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_hts1') ? get_option('woocommerce_endicia_xml_hts1') : '' // backwards
          ),

          'endicia_xml_customs_description' => array(
            'title'       => __('Customs Description (default)', 'woocommerce'),
            'description' => __('Your desired default description for customs declarations purposes', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_customs_description') ? get_option('woocommerce_endicia_xml_customs_description') : '' // backwards
          ),

          'endicia_xml_customs_type' => array(
            'title'       => __('Customs Type (default)', 'woocommerce'),
            'label'       => __('Your desired default content type for customs declarations purposes', 'woocommerce'),
            'type'        => 'select',
            'options'     => array(
              'MERCHANDISE'   => 'Merchandise',
              'DOCUMENTS'     => 'Documents',
              'SAMPLE'        => 'Sample',
              'GIFT'          => 'Gift',
              'RETURNEDGOODS' => 'Returned Goods',
              'OTHER'         => 'Other'
            ),
            'default'     => get_option('woocommerce_endicia_xml_customs_type') ? get_option('woocommerce_endicia_xml_customs_type') : 'MERCHANDISE'
          ),

          'endicia_xml_return_address_1' => array(
            'title'       => __('Return Address Line 1', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_return_address_1') ? get_option('woocommerce_endicia_xml_return_address_1') : '' // backwards
          ),

          'endicia_xml_return_address_2' => array(
            'title'       => __('Return Address Line 2', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_return_address_2') ? get_option('woocommerce_endicia_xml_return_address_2') : '' // backwards
          ),

          'endicia_xml_return_address_3' => array(
            'title'       => __('Return Address Line 3', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_return_address_3') ? get_option('woocommerce_endicia_xml_return_address_3') : '' // backwards
          ),

          'endicia_xml_return_address_4' => array(
            'title'       => __('Return Address Line 4', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_return_address_4') ? get_option('woocommerce_endicia_xml_return_address_4') : '' // backwards
          ),

          'endicia_xml_return_address_5' => array(
            'title'       => __('Return Address Line 5', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_return_address_5') ? get_option('woocommerce_endicia_xml_return_address_5') : '' // backwards
          ),

          'endicia_xml_return_address_6' => array(
            'title'       => __('Return Address Line 6', 'woocommerce'),
            'type'        => 'text',
            'default'     => get_option('woocommerce_endicia_xml_return_address_6') ? get_option('woocommerce_endicia_xml_return_address_6') : '' // backwards
          )

        );

      } // End init_form_fields()


      /**
       * Adds the "Download Endicia XML" button and other Endicia content to the single Order view in the admin dashboard
       */
      function add_endicia_xml_panel() {

        // Calculate the total weight of this order
        $totalWeight = $this->get_order_weight();
        $weightUnit  = get_option('woocommerce_weight_unit');

        $isInternational = ( $this->order->shipping_country != 'US' );

        // Only present customs options if the shipping method is 'international'
        $customsOptions = '';
        if ($isInternational) {

          // Simplify our settings variables
          $settings           = $this->settings;

          $customsHTS1        = $settings['endicia_xml_customs_hts1'];
          $customsDescription = $settings['endicia_xml_customs_description'];

          $customsOptions = <<< END
              <li class="wide">
                <label for="customs-hts">Customs HTS:</label>
                <input type="text" name="customs_hts" id="customs-hts" value="{$customsHTS1}" />
              </li>
              <li class="wide">
                <label for="customs-description">Customs Description:</label>
                <input type="text" name="customs_description" id="customs-description" value="{$customsDescription}" />
              </li>
              <li class="wide">
                <label for="customs-type">Customs Type:</label>
                <select name="customs_type" id="customs_type">
                  <option value="MERCHANDISE">Merchandise</option>
                  <option value="DOCUMENTS">Documents</option>
                  <option value="SAMPLE">Sample</option>
                  <option value="GIFT">Gift</option>
                  <option value="RETURNEDGOODS">Returned Goods</option>
                  <option value="OTHER">Other</option>
                </select>
              </li>
END;
        }

        // Generate the dropdown to select layout file
        $internationalLayoutSelected = ($isInternational) ? ' selected="selected" ' : '';
        $layoutFileOptions = <<< END

          <li class="wide">
            <label for="endicia-layout-file">Label Layout:</label>
            <select name="endicia_layout_file" id="endicia-layout-file">
              <option value="Priority Mail Shipping Label.lyt">Priority Mail</option>
              <option value="Large Priority Mail International Shipping Label.lyt" {$internationalLayoutSelected}>Large Priority Mail International</option>
              <option value="Small Priority Mail International Shipping Label.lyt">Small Priority Mail International Shipping Label</option>
            </select>
END;

        echo <<< END

          <div class="clear"></div>
        </div>
        <div class="totals_group">
          <h4>Endicia XML</h4>
          <ul class="totals">
            <li class="wide">
              <label for="order-weight">Total Order Weight (in {$weightUnit}):</label>
              <input type="text" name="order-weight" id="order-weight" value="{$totalWeight}" />
            </li>
            <li class="wide">
              <label for="endicia-mail-class">Mail Class:</label>
              <select name="endicia_mail_class" id="endicia-mail-class">
                <option value="PRIORITY">Priority Mail</option>
                <option value="FIRST">First-Class Mail</option>
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
              <label for="endicial-package-type">Package Type:</label>
              <select name="endicia_package_type" id="endicia-package-type">
                <!-- <option value="FLATRATEBOX">Flat Rate Box</option> -->
                <option value="FLATRATEENVELOPE">Flat Rate Envelope</option>
                <!-- <option value="FLATRATEMEDIUMBOX">Flat Rate Medium Box</option>
                <option value="FLATRATELARGEBOX">Flat Rate Large Box</option>
                <option value="FLATRATESMALLBOX">Flat Rate Small Box</option>
                <option value="FLATRATEPADDEDENVELOPE">Flat Rate Padded Envelope</option>
                <option value="FLATRATELEGALENVELOPE">Flat Rate Legal Envelope</option> -->
                <option value="RECTPARCEL">Rectangular Parcel</option>
                <!-- <option value="NONRECTPARCEL">Non-rectangular Parcel</option>
                <option value="POSTCARD">Postcard</option>
                <option value="ENVELOPE">First-Class Mail Letter</option> -->
                <option value="FLAT">First-Class Mail Large Envelope</option>
                <!-- <option value="REGIONALRATEBOXA">Regional Rate &#8212; Box A</option>
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
                <option value="SACK">Sack (for PMOD and EMOD)</option> -->
              </select>
            </li>
            {$layoutFileOptions}
            {$customsOptions}
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
       * Calculates the total weight for this order based on the product variations contained in the order
       */
      function get_order_weight() {

        $totalWeight = 0;

        // Query information about our order, based on the ID in the query string
        $orderID  = ( isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) ? $_GET['post'] : null;
        $this->order = ( $orderID != null) ? new WC_Order( $orderID ) : null;

        if ( $this->order != null ) {

          // WC_Order has the list of items for this order...
          $items = unserialize( $this->order->order_custom_fields['_order_items'][0] );

          if ( is_array( $items ) ) {

            foreach ( $items as $item ) {

              // And then WC_Product or WC_Product_Variation can leak to us the weight of the item!
              $product = ( $item['variation_id'] != '') ?
                new WC_Product_Variation( $item['variation_id'] ) :
                new WC_Product( $item['id'] );

              $totalWeight += ( $product->weight * $item['qty'] );

            }

          }

        }

        return $totalWeight;

      } // ends add_weight_info()



      /**
       * Handles the $_POST data and fires off the request for postage when the Generate Postage button has been clicked
       */
      function handle_post() {

        if ((isset($_POST['endicia_generate_xml'])) && ($_POST['endicia_generate_xml'] != '')) {

          // Simplify our settings variables
          $settings = $this->settings;

          // Get the full directory path for DAZzle's document folder
          $dazzleDirectory = '';
          if (isset($settings['endicia_xml_directory']) || array_key_exists('endicia_xml_directory', $settings)) {
            $dazzleDirectory = $settings['endicia_xml_directory'];
          }

          // Validate output file location
          $outputFile = '';
          if (isset($settings['endicia_xml_output_file']) || array_key_exists('endicia_xml_output_file', $settings)) {
            $outputFile = $dazzleDirectory . $settings['endicia_xml_output_file'];
          }

          // Validate layout file
          $layoutFile = '';
          if (isset($_POST['endicia_layout_file']) || array_key_exists('endicia_layout_file', $_POST)) {
            $layoutFile = $dazzleDirectory . $_POST['endicia_layout_file'];
          }

          // Validate testing mode YES|NO
          $testingMode = 'YES';
          if (isset($settings['endicia_xml_testing_mode']) || array_key_exists('endicia_xml_testing_mode',$settings)) {
            $testingMode = strtoupper($settings['endicia_xml_testing_mode']);
            $testingMode = (($testingMode == 'YES') || ($testingMode == 'NO')) ? $testingMode : 'YES';
          }

          // Validate Prompt YES|NO
          $prompt = 'NO';
          if (isset($settings['endicia_xml_prompt']) || array_key_exists('endicia_xml_prompt', $settings)) {
            $prompt = strtoupper($settings['endicia_xml_prompt']);
            $prompt = ($prompt == 'YES') ? 'YES' : 'NO';
          }

          // Validate start="PRINTING" YES|NO
          $immediatePrint = 'NO';
          if (isset($settings['endicia_xml_start']) || array_key_exists('endicia_xml_start', $settings)) {
            $immediatePrint = strtoupper($settings['endicia_xml_start']);
            $immediatePrint = ($immediatePrint == 'YES') ? 'PRINTING' : 'NO';
          }

          // Validate AutoPrintCustomsForms YES|NO
          $autoPrintCustomsForms = 'YES';
          if (isset($settings['endicia_xml_auto_print_customs_forms']) || array_key_exists('endicia_xml_auto_print_customs_forms',$settings)) {
            $autoPrintCustomsForms = strtoupper($settings['endicia_xml_auto_print_customs_forms']);
            $autoPrintCustomsForms = (($autoPrintCustomsForms == 'YES') || ($autoPrintCustomsForms == 'NO')) ? $autoPrintCustomsForms : 'YES';
          }

          // Validate stealth mode
          $stealth = 'FALSE';
          if (isset($settings['endicia_xml_stealth']) || array_key_exists('endicia_xml_stealth',$settings)) {
            $stealth = strtoupper($settings['endicia_xml_stealth']);
            $stealth  = ($stealth == 'YES') ? 'TRUE' : 'FALSE';
          }

          // Validate Customs Certify mode
          $customsCertify = 'FALSE';
          if (isset($settings['endicia_xml_customs_certify']) || array_key_exists('endicia_xml_customs_certify',$settings)) {
            $customsCertify = strtoupper($settings['endicia_xml_customs_certify']);
            $customsCertify  = ($customsCertify == 'YES') ? 'TRUE' : 'FALSE';
          }

          // Validate the Customs Signer naem
          $customsSigner = '';
          if (isset($settings['endicia_xml_customs_signer']) || array_key_exists('endicia_xml_customs_signer', $settings)) {
            $customsSigner = $settings['endicia_xml_customs_signer'];
          }

          // Validate the Customs HS Tariff Schedule Number, which might have been overwritten per-order
          $customsHTS1 = '';
          if (isset($_POST['customs_hts']) || array_key_exists('customs_hts', $_POST)) {
            $customsHTS1 = $_POST['customs_hts'];
          }

          // Validate Customs Description, which might have been overwritten per-order
          $customsDescription = '';
          if (isset($_POST['customs_description']) || array_key_exists('customs_description', $_POST)) {
            $customsDescription = $_POST['customs_description'];
          }

          // Validate the Customs Type
          $customsType = '';
          if (isset($_POST['customs_type']) || array_key_exists('customs_type', $_POST)) {
            $customsType = $_POST['customs_type'];
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

          // Add up and validate the quantity
          $quantity = array_sum( $_POST['item_quantity'] );

          // Grab the Mail Class and Package Type from the dropdowns
          $mailClass   = ( $_POST['endicia_mail_class']   != '')  ? $_POST['endicia_mail_class']   : '';
          $packageType = ( $_POST['endicia_package_type'] != '' ) ? $_POST['endicia_package_type'] : '';

          // Validate the destination country
          $toCountry  = '';
          $customsXML = '';
          if ( isset( $_POST['_shipping_country'] ) || array_key_exists( '_shipping_country', $_POST ) ) {

            $toCountryCode   = $_POST['_shipping_country'];
            $countryHandler  = new WC_Countries;

            // Verify that the shipping country 2-digit code is recognized, and if so, translate it
            $toCountry = ( isset( $countryHandler->countries[$toCountryCode] ) ) ?
              $countryHandler->countries[$toCountryCode] :
              $toCountryCode;
          }

          // Build the XML for the customs forms
          $customsXML = ( $toCountryCode != 'US' ) ?
           "<ToCountry>{$toCountry}</ToCountry>
            <CustomsQuantity1>{$quantity}</CustomsQuantity1>
            <CustomsDescription1>{$customsDescription}</CustomsDescription1>
            <CustomsWeight1>{$weight}</CustomsWeight1>
            <CustomsValue1>{$_POST['_order_total']}</CustomsValue1>
            <CustomsCountry1>USA</CustomsCountry1>
            <CustomsHTS1>{$customsHTS1}</CustomsHTS1>
            <ContentsType>{$customsType}</ContentsType>
            <CustomsSigner>{$customsSigner}</CustomsSigner>
            <CustomsCertify>{$customsCertify}</CustomsCertify>" : '';

          // Validate the Return Address fields
          $returnAddress1 = $settings['endicia_xml_return_address_1'];
          $returnAddress2 = $settings['endicia_xml_return_address_2'];
          $returnAddress3 = $settings['endicia_xml_return_address_3'];
          $returnAddress4 = $settings['endicia_xml_return_address_4'];
          $returnAddress5 = $settings['endicia_xml_return_address_5'];
          $returnAddress6 = $settings['endicia_xml_return_address_6'];

          $output = <<< END
<DAZzle OutputFile='{$outputFile}' Layout='{$layoutFile}' Start='{$immediatePrint}' Test='{$testingMode}' Prompt='{$prompt}' AutoClose='NO' AutoPrintCustomsForms='{$autoPrintCustomsForms}'>
  <Package ID='1'>
    <MailClass>{$mailClass}</MailClass>
    <PackageType>{$packageType}</PackageType>
    <Stealth>{$stealth}</Stealth>
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
    <ReturnAddress1>{$returnAddress1}</ReturnAddress1>
    <ReturnAddress2>{$returnAddress2}</ReturnAddress2>
    <ReturnAddress3>{$returnAddress3}</ReturnAddress3>
    <ReturnAddress4>{$returnAddress4}</ReturnAddress4>
    <ReturnAddress5>{$returnAddress5}</ReturnAddress5>
    <ReturnAddress6>{$returnAddress6}</ReturnAddress6>
    {$customsXML}
  </Package>
</DAZzle>
END;
        # echo '<pre>'; print_r($output); echo '</pre>';exit;
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