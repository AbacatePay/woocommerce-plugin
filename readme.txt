=== AbacatePay for WooCommerce ===
Contributors: abacatepay
Tags: pix, boleto, credit card, payment gateway, brazil
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Pix, credit card and boleto payments with AbacatePay, with automatic order confirmation.

== Description ==

Customers complete the purchase and see the Pix QR Code and the Pix Copy and Paste code on the order page, without leaving the store. As soon as the Pix is paid, the order is confirmed automatically.

* Transparent checkout: Pix QR Code and Copy and Paste code on the "order received" page, in "My account" and in the order email.
* Automatic confirmation through webhooks, with an order page that refreshes by itself.
* Automatic cancellation (with stock restored) when the Pix expires without payment.
* Refunds from the order screen (AbacatePay only supports full refunds).
* Refunds and disputes made outside WooCommerce also update the order.
* Dev mode: test charges and a "Simulate Pix payment" action on the order.
* Credit card in up to 12 installments, with the secure AbacatePay card form (3DS included). Card data never passes through the store.
* Boleto: digitable line, printable boleto link and an alternative Pix for the same charge, with configurable due date, interest and fine.
* Compatible with the block-based checkout and with HPOS.
* Sends the customer's CPF/CNPJ when the store uses the "Brazilian Market on WooCommerce" plugin.

== External services ==

This plugin connects to [AbacatePay](https://www.abacatepay.com), a Brazilian payment provider, to create and manage the charges of the orders paid with it. It is required for the plugin to work.

* **AbacatePay API** (`https://api.abacatepay.com/v2`): when a customer places an order with Pix, credit card or boleto, the plugin sends the order amount, description, order ID and number and the customer's name, email, phone and CPF/CNPJ to create the charge. For credit cards it sends a card token instead of the card data. It also queries the charge status, refunds charges when the store issues a refund and, in Dev mode, simulates payments. All requests are authenticated with the store's API key.
* **AbacatePay webhooks**: AbacatePay sends payment, refund and dispute events about these charges to the store's webhook URL.
* **abacatepay.js** (`https://abacatepay.com/abacatepay.js`): loaded on the checkout page only when the credit card method is enabled. It renders the card form in a secure iframe and turns the card data into a token in the customer's browser, sending the card data directly to AbacatePay.

AbacatePay terms of use and privacy policy: https://www.abacatepay.com/termos

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` and activate the plugin.
2. Go to WooCommerce > Settings > Payments > AbacatePay Pix.
3. In the AbacatePay dashboard (Integration), create an API key with the `TRANSPARENT:CREATE`, `TRANSPARENT:READ` and `REFUND:CREATE` permissions and paste it in "API key". A Dev mode key creates test charges.
4. In the AbacatePay dashboard, create a webhook with the URL and secret shown on the settings screen, subscribed to the `transparent.completed`, `transparent.refunded`, `transparent.disputed` and `transparent.lost` events. The URL must be public and use HTTPS.
5. The store currency must be the Brazilian real (BRL).
6. For credit cards: in WooCommerce > Settings > Payments > AbacatePay Credit Card, paste the public key (`pk_...`, under Integration > Own checkout in the dashboard), from the same environment as the API key.

== Frequently Asked Questions ==

= Is the order confirmed without the webhook? =

Yes, but with a delay: while the customer is on the order page, it checks the status with AbacatePay every few seconds. A scheduled task also checks the charge right after the Pix expires. The webhook is still the recommended setup.

= How can I test without real money? =

Use a Dev mode API key. Place an order with Pix and, on the order screen in the admin, choose the "Simulate Pix payment (Dev mode)" action.

= Can I customize how the QR Code looks? =

Yes. Copy the files from the `templates/` folder to `your-theme/abacatepay-for-woocommerce/` and edit them.

== Changelog ==

= 1.0.0 =
* First release: Pix, credit card and boleto through the AbacatePay transparent checkout.
