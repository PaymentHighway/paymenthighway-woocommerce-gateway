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

    /**
     * process_subscription_payment function.
     * @param WC_Order $order
     * @param int $amount (default: 0)
     * @param string $stripe_token (default: '')
     * @param  bool initial_payment
     * @return array|WP_Error
     */
    public function process_subscription_payment( $order, $amount = 0 ) {
        $this->logger->info( "Begin processing subscription payment for order {$order->get_id()} for the amount of {$amount}" );

        $token = WC_Payment_Tokens::get_customer_default_token($order->get_customer_id());

        if($token->get_gateway_id() !== parent::get_id()) {
            $tokens = WC_Payment_Tokens::get_customer_tokens( $order->get_customer_id(), parent::get_id());
            if(count($tokens) === 0) {
                $this->logger->alert('Customer' . $order->get_customer_id . ' does not have any stored cards in Payment Highway.');
                return new WP_Error('', 'Customer' . $order->get_customer_id . ' does not have any stored cards in Payment Highway.');
            }
            /**
             * @var WC_Payment_Token_CC $t
             */
            foreach ($tokens as $t) {
                if($t->get_expiry_year() >= date("Y") && $t->get_expiry_month() >= date('m')){
                    $token = $t;
                    break;
                }
            }
        }

        $forms = parent::get_forms();


        // Make the request
        $request             = $this->generate_payment_request( $order, $source );
        $request['capture']  = 'true';
        $request['amount']   = $this->get_stripe_amount( $amount, $request['currency'] );
        $request['metadata'] = array(
            'payment_type'   => 'recurring',
            'site_url'       => esc_url( get_site_url() ),
        );
        $response            = WC_Stripe_API::request( $request );

        // Process valid response
        if ( is_wp_error( $response ) ) {
            if ( 'missing' === $response->get_error_code() ) {
                // If we can't link customer to a card, we try to charge by customer ID.
                $request             = $this->generate_payment_request( $order, $this->get_source( ( $this->wc_pre_30 ? $order->customer_user : $order->get_customer_id() ) ) );
                $request['capture']  = 'true';
                $request['amount']   = $this->get_stripe_amount( $amount, $request['currency'] );
                $request['metadata'] = array(
                    'payment_type'   => 'recurring',
                    'site_url'       => esc_url( get_site_url() ),
                );
                $response          = WC_Stripe_API::request( $request );
            } else {
                return $response; // Default catch all errors.
            }
        }

        $this->process_response( $response, $order );

        return $response;
    }

    /**
     * Is $order_id a subscription?
     * @param  int  $order_id
     * @return boolean
     */
    protected function is_subscription( $order_id ) {
        return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
    }


    /**
     * Process the payment based on type.
     * @param  int $order_id
     * @return array
     */
    public function process_payment( $order_id, $must_be_logged_in = false ) {
        if ( $this->is_subscription( $order_id ) ) {
            return parent::process_payment( $order_id, true );

        } else {
            return parent::process_payment( $order_id, $must_be_logged_in );
        }
    }

}