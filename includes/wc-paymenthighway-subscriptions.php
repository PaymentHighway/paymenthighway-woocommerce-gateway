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
            $renewal_order->update_status( 'failed', sprintf( __( 'Payment Highway Transaction Failed (%s)', 'wc-payment-highway' ), $response->get_error_message() ) );
        }
    }

    /**
     * process_subscription_payment function.
     *
     * @param WC_order $order
     * @param int $amount (default: 0)
     * @uses  Simplify_BadRequestException
     * @return bool|WP_Error
     */
    public function process_subscription_payment( $order, $amount = 0 ) {
        if($amount === 0) {
            $order->payment_complete();
            return true;
        }
        $this->logger->info( "Begin processing subscription payment for order {$order->get_id()} for the amount of {$amount}" );

        $customerId = $order->get_customer_id();
        if ( !$customerId ) {
            return new WP_Error( 'paymenthighway_error', __( 'Customer not found.', 'woocommerce' ) );
        }

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
        if($this->checkToken($token)){
            $forms = parent::get_forms();

            $response = $forms->payWithToken($token->get_token(), $order, $amount, get_woocommerce_currency());
            $responseObject = json_decode($response);

            if($responseObject->result->code !== 100) {
                if($responseObject->result->code === 200) {
                    $errorMsg = "Payment rejected. Token:  {$token->get_token()}. Order: {$order->get_id()}, error: {$responseObject->result->code}, message: {$responseObject->result->message}";
                    $this->logger->info($errorMsg);
                    $order->add_order_note( sprintf( __( 'Payment Highway payment rejected: %s.', 'wc-payment-highway' ), $errorMsg ));
                }
                else{
                    $errorMsg = "Error while trying to charge token: {$token->get_token()}. Order: {$order->get_id()}, error: {$responseObject->result->code}, message: {$responseObject->result->message}";
                    $this->logger->alert($errorMsg);
                    $order->add_order_note( sprintf( __( 'Payment Highway payment error: %s.', 'wc-payment-highway' ), $errorMsg ));
                }


                return new WP_Error( 'paymenthighway_error', __( 'Error while trying to charge token.', 'woocommerce' ) );

            }

            $order->payment_complete();
            $order->add_order_note( __( 'Payment Highway payment completed.', 'wc-payment-highway' ) );
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * @param WC_Payment_Token_CC $token
     * @return boolean
     */
    private function checkToken( $token ) {
        if ( $token->get_gateway_id() !== parent::get_id() ) {
            return false;
        } elseif ( $token->get_expiry_year() >= date( "Y" ) && $token->get_expiry_month() >= date( 'm' ) ) {
            $this->logger->info("Expired token: {$token->get_token()}");
            return true;
        } else {
            return false;
        }
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