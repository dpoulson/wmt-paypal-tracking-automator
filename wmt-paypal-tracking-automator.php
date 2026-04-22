<?php
/**
 * Plugin Name: WMT PayPal Tracking Automator
 * Description: Automatically extracts tracking numbers from order notes and pushes them to PayPal for orders paid via PayPal.
 * Version: 1.0.5
 * Author: We Make Things
 * Author URI: https://wemakethings.co.uk
 * Text Domain: wmt-paypal-tracking-automator
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 * Tested up to: 6.9
 */
if (!defined('ABSPATH'))
    exit;

// Define the path to ensure no loading errors
define('WMT_PP_TRACKING_PATH', plugin_dir_path(__FILE__));

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        require_once WMT_PP_TRACKING_PATH . 'includes/class-wmt-paypal-sync.php';
        new WMT_PayPal_Sync();
    }
});

// Settings remain the same
add_filter('woocommerce_general_settings', function ($settings) {
    $settings[] = [
        'title' => __('WMT PayPal Tracking Automator', 'wmt-paypal-tracking-automator'),
        'type' => 'title',
        'id' => 'wmt_paypal_tracking_section'
    ];
    $settings[] = [
        'title' => __('Enable Debug Mode', 'wmt-paypal-tracking-automator'),
        'id' => 'wmt_paypal_tracking_debug_mode',
        'default' => 'no',
        'type' => 'checkbox'
    ];
    $settings[] = [
        'type' => 'sectionend',
        'id' => 'wmt_paypal_tracking_section'
    ];
    return $settings;
});


/**
 * Fix for "no callbacks are registered" in Action Scheduler.
 * Forces the PayPal Tracking module to load when the sync action is triggered.
 */
add_action('woocommerce_paypal_payments_tracking_sync', function () {
    $file = WP_PLUGIN_DIR . '/woocommerce-paypal-payments/modules/ppcp-order-tracking/module.php';
    if (file_exists($file)) {
        $factory = require_once $file;
        if (is_callable($factory)) {
            // This initializes the module and registers the internal listeners
            $factory();
        }
    }
}, 5); // Priority 5 runs BEFORE the worker tries to find the callback

// Store the container in a global-ish way so our script can grab it
add_action('woocommerce_paypal_payments_built_container', function ($container) {
    $GLOBALS['ppcp_container'] = $container;
});
