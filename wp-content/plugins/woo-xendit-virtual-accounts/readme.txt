=== Plugin Name ===
Contributors: Xendit
Donate link: #
Tags: xendit, woocommerce, payment, payment gateway, virtual account
Requires at least: 3.0.1
Tested up to: 5.3.2
Stable tag: 1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Xendit Payment Gateway for WooCommerce

== Description ==

Integrate the following payment methods on the checkout page:
- Credit Card (Mastercard, VISA, JCB)
- Bank Transfers (BCA, BNI, BRI, Mandiri, Permata)
- eWallet (OVO)
- Alfamart
- Cardless Credit (Kredivo)

== Installation ==

1. Make sure you have [WooCommerce](https://wordpress.org/plugins/woocommerce/) installed.
2. Upload `plugin-name.php` to the `/wp-content/plugins/` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Navigate to WooCommerce -> Settings -> Payments (tab) in your WordPress dashboard.
5. Enable `Xendit – Payment Gateway` & click Manage.
5. Fill out all the required details including your Public & Secret API Key which can be found in your Xendit dashboard (https://dashboard.xendit.co/settings/developers#api-keys). You can toggle between Test or Live Environment, then save your changes.
6. Navigate back to the Payments (tab) page & toggle all the Xendit Payment Gateways that you want to enable.
7. You may also manually change each Payment Gateway's name & description (by clicking the Manage button) that will appear in your checkout page.
8. Adjust your settings and save. You can see the new payment options by viewing your WooCommerce checkout page while you have items in the cart.

== Changelog ==

= 2.8.0 =
* Add indomaret payment method
* Move invoice creation hook
* Update outdated external links

= 2.7.2 =
* Fix CC order status update

= 2.7.1 =
* Fix redirect load
* Fix subscription timeout issue
* Fix form name

= 2.7.0 =
* add LINKAJA payment method
* Reconfigure Dynamic 3DS

= 2.6.1 =
* Improving experience of cards status update
* Improve item information handle

= 2.6.0 =
* add DANA payment method
* Fix bug

= 2.5.0 =
* Add new settings to determine invoice flow
* Handle redirect through order received page

= 2.4.2 =
* Change CC status update flow
* Refine card validation message in checkout page

= 2.4.1 =
* Check WC order status before processing callback

= 2.4.0 =
* Change OVO flow to reduce timeout case

= 2.3.2 =
* Add x-api-version header for OVO payment

= 2.3.1 =
* Fix external id format to follow API guidelines

= 2.3.0 =
* Change flow credit card charge

= 2.2.4 =
* Add more logs in the main checkout and callback process

= 2.2.3 =
* should_authenticate is more important than should_3ds

= 2.2.2 =
* Fix escape characters

= 2.2.1 =
* Miscellaneous bug fixes

= 2.2.0 =
* New cardless credit payment method - Kredivo

= 2.1.0 =
* Add dynamic 3DS feature
* Enhance subscription feature to make it usable for all merchant

= 2.0.0 =
* Merged credit card payment method - 1 plugin for all.

= 1.8.2 =
* Enhancement: Bypass minimum amount for OVO on development mode

= 1.8.1 =
* Enhancement: Use callback endpoint for ewallet completion process to make it more reliable

= 1.8.0 =
* New feature: Enable merchant to change payment description in checkout page

= 1.7.2 =
* Fix order cancellation that affect orders that are created not using xendit

= 1.7.1 =
* Remove individual enable checkmark on each payment method

= 1.7.0 =
* New feature: Enable merchant to change payment method name

= 1.6.1 =
* Bugfix faulty notification URL for new external id

= 1.6.0 =
* Add custom external id form
* Rearrange Xendit setting page

= 1.5.1 =
* Fix failed publish

= 1.5.0 =
* Add refund function

= 1.4.2 =
* Add custom expiry time in admin options

= 1.4.1 =
* Fix faulty amount validation

= 1.4.0 =
* Remove callback URL interface

= 1.3.0 =
* Add OVO payment method

= 1.2.6 =
* Add alert when changing API key

= 1.2.5 =
* Fix wrong enum names

= 1.2.3 =
* Improve logging

= 1.2.2 =
* Fix issue where notification is not processed correctly

= 1.2.1 =
* Fix callback issue

= 1.2.0 =
* Change creds field to password
* Add alfamart payment method

= 1.1.1 =
* Minor bugfix for API request

= 1.1.0 =
* Split VA payment method to individual bank
* No longer display account number info in checkout/order received page
* Now use redirect scheme with Xendit invoice UI

= 1.0.5 =
* Fix minor visual bug on Xendit icon

= 1.0.4 =
* Change plugin description

= 1.0.3 =
* Improve stability
* Add measurement

= 1.0.2 =
* Fixed amount calculation for checkout page

= 1.0.1 =
* Automatic order cancellation for expired Xendit invoice.
* Fixed bank order display

= 1.0 =
* Initial version.

== Upgrade Notice ==

= 1.0 =
Initial version.
