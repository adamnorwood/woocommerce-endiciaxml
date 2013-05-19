WooCommerce Extension: Endicia / DAZzle XML
===========================================

This WordPress plugin extends WooCommerce with basic integration with the [Endicia DAZzle](http://www.dymoendicia.com/) brand of shipping label printing software through their DAZzle XML file import / watch feature.

## Requirements

* WordPress 3.5 or greater (might work with earlier versions, but hasn't been tested).
* **Known to work with WooCommerce 1.6.6.** Hasn't been tested yet with WooCommerce 2.x (but hopefully the API hasn't changed too much…).

## Installation

1. Place the `woocommerce-endicia-xml/` directory and its contents in your WordPress `plugins/` directory.
2. Enable the **WooCommerce Endicia XML** plugin from the WordPress Dashboard > Plugins menu.
3. Once the plugin is enabled, you will now find an **Endicia XML** tab on the WooCommerce > Settings > Integration page.
4. Configure the settings on the **Integration > Endicia XML** page to match your DAZzle setup. Be sure to check **Testing Mode** when doing initial tests — with Testing Mode *off* you'll be printing live postage and will be charged accordingly!

## Printing Labels

* With this plugin enabled, when you open an Order using the **WooCommerce > Orders** panel, you should see a new **Endicia XML** section in the **Order Totals** widget.
* The *Total Order Weight* should be automatically generated based on the items in the order, but you can customize it if needed.
* Select the *Mail Class*, *Package Type*, and *Label Layout* that you need for this order.
* Click the **Download Endicia XML** button to generate the XML needed to print the shipping label for this order.
* Open the saved .xml file in Edicia DAZzle, or if you have DAZzle configured to "watch" your download folder the label will begin to print automatically. DAZzle may prompt you for more information if necessary to complete the label.
* **NOTE:** the settings you choose for this Order do not get saved, so if you need to re-print the label be sure to verify that you're using the correct shipment method / layout etc.!

## Contibuting

If you're interested in helping out, or if you find a bug in this code (very likely!), please drop in an [Issue on GitHub](https://github.com/adamnorwood/woocommerce-endiciaxml/issues?state=open) or fork the code and submit a pull request. Thanks!
