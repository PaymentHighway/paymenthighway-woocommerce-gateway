<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_Payment_Highway_Subscriptions extends WC_Gateway_Payment_Highway {

    public function __construct() {
        parent::__construct();
        if ( class_exists( 'WC_Subscriptions_Order' ) ) {
            add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
        }
    }

    /**
     * scheduled_subscription_payment function.
     *
     * @param $amount_to_charge float The amount to charge.
     * @param $renewal_order WC_Order A WC_Order object created to record the renewal payment.
     */
    public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {
        $response = $this->process_subscription_payment( $renewal_order, $amount_to_charge );

        if ( is_wp_error( $response ) ) {
            $renewal_order->update_status( 'failed', sprintf( __( 'Stripe Transaction Failed (%s)', 'woocommerce-gateway-stripe' ), $response->get_error_message() ) );
        }
    }

    public function process_subscription_payment( $order = '', $amount = 0 ) {

    }
}