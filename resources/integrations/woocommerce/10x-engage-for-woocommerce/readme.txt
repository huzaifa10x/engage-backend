=== 10X Engage for WooCommerce ===
Contributors: 10xdigital
Tags: whatsapp, woocommerce, attribution, orders
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.6
WC tested up to: 9.5
Stable tag: 1.0.0
License: GPLv2 or later

See which WhatsApp chats became orders.

== Description ==

* Installs the 10X Engage website tracker automatically (no snippet to paste).
* Links sent from a 10X Engage chat carry a signed token (?xl=…). It is kept in the WooCommerce session and a first-party cookie, so it survives logging in, the cart and payment redirects, and is saved on the order at checkout (classic and block checkout).
* Sends signed order events to 10X Engage: created, paid, completed, refunded (full and partial), cancelled, failed, pending and edits. Events are queued and retried if 10X Engage cannot be reached, and a nightly catch-up sends anything missing.
* Works with High-Performance Order Storage (HPOS) and the older order storage.
* Reads orders and products only; it never changes your store's data.

== Installation ==

1. In 10X Engage, open Integrations → WooCommerce and choose “Get workspace key”, then download the plugin.
2. In WordPress, go to Plugins → Add New → Upload Plugin, upload the zip and activate it.
3. Go to WooCommerce → 10X Engage, paste the key and choose Connect.

== Privacy ==

For each order the plugin sends the order number, status, totals, currency, billing name, phone and country, line items, WooCommerce's own order attribution and the chat link token. It sends no payment details, no email address and no addresses.
