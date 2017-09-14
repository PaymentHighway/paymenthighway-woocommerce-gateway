<?php
/**
 * Plugin Name: WooCommerce Payment Highway
 * Plugin URI: https://paymenthighway.fi/en/
 * Description: WooCommerce Payment Gateway for Payment Highway Credit Card Payments.
 * Author: Payment Highway
 * Author URI: https://paymenthighway.fi
 * Version: 1.1.0
 * Text Domain: wc-payment-highway
 * Domain Path: /languages
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



if ( ! class_exists( 'WC_PaymentHighway' ) ) :
    class WC_PaymentHighway {
        /**
         *  Singleton The reference the *Singleton* instance of this class
         */
        private static $instance;

        /**
         *  Reference to logging class.
         */
        private static $log;

        /**
         * Returns the *Singleton* instance of this class.
         *
         * @return WC_PaymentHighway The *Singleton* instance.
         */
        public static function get_instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Private clone method to prevent cloning of the instance of the
         * *Singleton* instance.
         *
         * @return void
         */
        private function __clone() {}

        /**
         * Private unserialize method to prevent unserializing of the *Singleton*
         * instance.
         *
         * @return void
         */
        private function __wakeup() {}

        /**
         * Flag to indicate whether or not we need to load code for / support subscriptions.
         *
         * @var bool
         */
        private $subscription_support_enabled = false;

        /**
         * Protected constructor to prevent creating a new instance of the
         * *Singleton* via the `new` operator from outside of this class.
         */
        protected function __construct() {
            add_action( 'plugins_loaded', array( $this, 'init' ) );
        }

        /**
         * Init the plugin after plugins_loaded so environment variables are set.
         */
        public function init() {
            // Don't hook anything else in the plugin if we're in an incompatible environment
            if ( self::check_environment() ) {
                return;
            }


            // Init the gateway itself
            $this->init_gateways();

            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
        }

        /**
         * Checks the environment for compatibility problems.  Returns a string with the first incompatibility
         * found or false if the environment has no problems.
         */
        static function check_environment() {
            if ( version_compare( phpversion(), WC_PAYMENTHIGHWAY_MIN_PHP_VER, '<' ) ) {
                $message = __( ' The minimum PHP version required for Payment Highway is %1$s. You are running %2$s.', 'wc-payment-highway' );

                return sprintf( $message, WC_PAYMENTHIGHWAY_MIN_PHP_VER, phpversion() );
            }

            if ( ! defined( 'WC_VERSION' ) ) {
                return __( 'WooCommerce needs to be activated.', 'wc-payment-highway' );
            }

            if ( version_compare( WC_VERSION, WC_PAYMENTHIGHWAY_MIN_WC_VER, '<' ) ) {
                $message = __( 'The minimum WooCommerce version required for Payment Highway is %1$s. You are running %2$s.', 'wc-payment-highway' );

                return sprintf( $message, WC_PAYMENTHIGHWAY_MIN_WC_VER, WC_VERSION );
            }

            return false;
        }

        /**
         * Initialize the gateway.
         *
         */
        public function init_gateways() {
            require_once( dirname( __FILE__ ) . '/includes/class-wc-gateway-payment-highway.php' );

            load_plugin_textdomain( 'wc-payment-highway', false, plugin_basename( dirname( __FILE__ ) ) . '/languages' );
            add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateways' ) );

            if ( class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' ) ) {
                $this->subscription_support_enabled = true;
                require_once( dirname( __FILE__ ) . '/includes/wc-paymenthighway-subscriptions.php' );
            }
        }

        /**
         * Add the gateway to WC Available Gateways
         *
         * @since 1.0.0
         *
         * @param array $methods all available WC gateways
         *
         * @return array
         */
        public function add_gateways( $methods ) {
            if ( $this->subscription_support_enabled ) {
                $methods[] = 'WC_Gateway_Payment_Highway_Subscriptions';
            } else {
                $methods[] = 'WC_Gateway_Payment_Highway';
            }
            self::log(print_r($methods, true));

            return $methods;
        }

        /**
         * Adds plugin page links
         *
         * @since 1.0.0
         *
         * @param array $links all plugin links
         *
         * @return array $links all plugin links + our custom links (i.e., "Settings")
         */
        public function plugin_action_links( $links ) {
            $plugin_links = array(
                '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payment_highway' ) . '">' . __( 'Settings', 'wc-payment-highway' ) . '</a>',
                '<a href="https://paymenthighway.fi/dev/">' . __( 'Docs', 'wc-payment-highway' ) . '</a>'
            );

            return array_merge( $plugin_links, $links );
        }

        /**
         * Logger
         *
         * @param $message
         */
        public static function log( $message ) {
            if ( empty( self::$log ) ) {
                self::$log = new WC_Logger();
            }

            self::$log->add( 'woocommerce-gateway-payment-highway', $message );
        }

    }

    $GLOBALS['wc_payment_highway'] = WC_PaymentHighway::get_instance();
endif;


function check_for_payment_highway_response() {
    global $paymentHighwaySuffixArray;
    $intersect = array_intersect(array_keys($_GET), $paymentHighwaySuffixArray);
    foreach ($intersect as $action) {
        // Start the gateways
        WC()->payment_gateways();
        do_action( $action );
    }
}

add_action( 'wp_loaded', 'check_for_payment_highway_response' );

