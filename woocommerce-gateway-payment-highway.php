<?php
/**
 * Plugin Name: WooCommerce Payment Highway
 * Plugin URI: https://paymenthighway.fi/en/
 * Description: WooCommerce Payment Gateway for Payment Highway Credit Card Payments.
 * Author: Payment Highway
 * Author URI: https://paymenthighway.fi
 * Version: 0.1
 * Text Domain: wc-payment-highway
 *
 * Copyright: © 2017 Payment Highway (support@paymenthighway.fi).
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Payment-Highway
 * @author    Payment Highway
 * @category  Admin
 * @copyright Copyright: © 2017 Payment Highway (support@paymenthighway.fi).
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 */

defined( 'ABSPATH' ) or exit;

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

add_action( 'plugins_loaded', 'init_payment_highway_class' );


/**
 * SETTINGS
 */
define( 'WC_PAYMENTHIGHWAY_MIN_PHP_VER', '5.4.0' );
define( 'WC_PAYMENTHIGHWAY_MIN_WC_VER', '3.0.0' );
$paymentHighwaySuffixArray = array(
    'paymenthighway_payment_success',
    'paymenthighway_add_card_success',
    'paymenthighway_add_card_failure',
    );

function check_for_payment_highway_response() {
    global $paymentHighwaySuffixArray;
    $intersect = array_intersect(array_keys($_GET), $paymentHighwaySuffixArray);
    foreach ($intersect as $action) {
        // Start the gateways
        WC()->payment_gateways();
        do_action( $action );
    }
}

add_action( 'init', 'check_for_payment_highway_response' );

/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 *
 * @param array $methods all available WC gateways
 *
 * @return array
 */
function add_payment_highway_to_gateways( $methods ) {
    $methods[] = 'WC_Gateway_Payment_Highway';

    return $methods;
}

add_filter( 'woocommerce_payment_gateways', 'add_payment_highway_to_gateways' );

/**
 * Adds plugin page links
 *
 * @since 1.0.0
 *
 * @param array $links all plugin links
 *
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_payment_highway_plugin_links( $links ) {
    $plugin_links = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payment_highway' ) . '">' . __( 'Settings', 'wc-payment-highway' ) . '</a>',
        '<a href="https://paymenthighway.fi/dev/">' . __( 'Docs', 'wc-payment-highway' ) . '</a>'
    );

    return array_merge( $plugin_links, $links );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_payment_highway_plugin_links' );


/**
 * WooCommerce Payment Highway
 *
 * @class        WC_Payment_Highway
 * @extends        WC_Payment_Gateway
 * @version        0.1
 * @package        WooCommerce/Classes/Payment
 * @author        Payment Highway
 */

function init_payment_highway_class() {
    include_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payment-highway.php' );
}