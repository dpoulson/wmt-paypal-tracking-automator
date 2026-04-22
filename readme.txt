=== WMT PayPal Tracking Automator ===
Contributors: wemakethings
Tags: woocommerce, paypal, tracking, automated
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically extracts tracking numbers from order notes and pushes them to PayPal for orders paid via PayPal Payments.

== Description ==

WMT PayPal Tracking Automator is a lightweight utility for WooCommerce stores that use the "PayPal Payments" (PPCP) plugin. It automatically detects tracking numbers in order notes and syncs them directly to the PayPal transaction using the PayPal API.

This ensures that your PayPal tracking information is always up to date without manual entry, which can help reduce disputes and speed up the release of funds.

Key Features:
* Automatic detection of tracking numbers in order notes.
* Direct sync to PayPal API.
* Support for Royal Mail, DPD, and Evri (identifies carrier from note content).
* Manual sync trigger from the order actions menu.

== Installation ==

1. Upload the `wmt-paypal-tracking-automator` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure you have the "WooCommerce PayPal Payments" plugin installed and configured.
4. (Optional) Configure settings under WooCommerce > Settings > General.

== Frequently Asked Questions ==

= Does this work with other PayPal plugins? =
Currently, it is specifically designed to work alongside the official "WooCommerce PayPal Payments" (PPCP) plugin as it retrieves credentials from its settings.

= How does it detect the tracking number? =
It looks for the pattern "Your tracking number is [NUMBER]" in order notes.

== Changelog ==

= 1.0.5 =
* Renamed domain to wmt-paypal-tracking-automator.
* Improved WordPress.org compliance.

= 1.0.0 =
* Initial release.
