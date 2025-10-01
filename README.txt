=== WebChain Order Sync ===
Contributors: ikobopay
Tags: webchain, woocommerce, blockchain, orders
Requires at least: 5.0
Tested up to: 6.8
Stable tag: 2.9.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WebChain Order Sync enables WooCommerce store owners to automatically broadcast completed orders to E-Talk's WebChain blockchain for fast, secure, and decentralized order tracking.

== Description ==
WebChain Order Sync automatically syncs WooCommerce orders to the WebChain blockchain via the E-Talk API. This allows merchants to have decentralized and tamper-proof order verification.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin via the 'Plugins' menu in WordPress.
3. Configure your WebChain **E-Talk Account Email** and **Wallet Address** in WooCommerce > Settings > WebChain.

== External Services ==
This plugin uses an external service to broadcast WooCommerce orders to the blockchain.

- **Service:** E-Talk WebChain API  
- **Purpose:** Sync WooCommerce completed orders to the WebChain blockchain for decentralized tracking.  
- **Data sent:**  
  - Merchant account email (for verification)  
  - Wallet address (to link blockchain account)  
  - Customer ID and email  
  - Order ID, amount, and currency  
  - Item details (product ID, name, quantity, price, SKU)  
- **Not transmitted:** Shipping addresses or payment card details.  
- **Endpoint:** https://e-talk.xyz/wp-json/webchain/v1  
- **Provider:** E-Talk (https://e-talk.xyz/)  
- **Privacy Policy:** https://e-talk.xyz/privacy-policy  
- **Terms of Service:** https://e-talk.xyz/terms-of-service  

This disclosure ensures transparency and informs users about what data is transmitted and why.

== Frequently Asked Questions ==

= Does this plugin send personal data? =
Yes. The plugin transmits limited order data to the E-Talk WebChain API when an order is completed.  
This includes: merchant account email, wallet address, customer ID, order ID, amount, currency, and item details.  
No shipping addresses or payment card details are ever transmitted.

= Why is this data sent? =
This data is required to record the transaction on the WebChain blockchain, allowing for decentralized and tamper-proof order tracking.

= Can I use this plugin without sharing order data externally? =
No. The core functionality of the plugin is to broadcast orders to the WebChain blockchain via the E-Talk API, so data transmission is necessary.

= Does this plugin comply with GDPR/CCPA? =
Yes. Store owners are responsible for informing customers in their own Privacy Policy that order data is shared with E-Talk WebChain for blockchain order verification. The plugin itself does not store any additional personal data beyond what WooCommerce already manages.

== Screenshots ==
1. Settings page (WooCommerce > WebChain) showing account email and wallet configuration.

== Changelog ==
= 2.9.2 =
* Added AJAX broadcast improvements
* Fixed customer email escaping in notifications
* Updated admin styles and assets enqueueing

= 2.9.1 =
* Fixed API sync for large orders
* Improved error handling
* Updated WebChain transaction library
