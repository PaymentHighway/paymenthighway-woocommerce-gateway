<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce Payment Highway
 *
 * @class          WC_Gateway_Payment_Highway
 * @extends        WC_Payment_Gateway_CC
 * @package        WooCommerce/Classes/Payment
 * @author         Payment Highway
 */
class WC_Gateway_Payment_Highway extends WC_Payment_Gateway_CC {

    public $logger;
    public $forms;
    public $subscriptions;
    private $accept_diners;
    private $accept_amex;
    protected $accept_cvc_required;
    protected $accept_orders_with_cvc_required;
    protected $save_all_credit_cards;
    const PH_REQUEST_SUCCESSFUL = 100;
    const PH_RESULT_FAILURE = 200;
    const PH_RESULT_SOFT_DECLINE = 400;

    public function __construct() {
        global $paymentHighwaySuffixArray;

        $this->subscriptions = false;
        $this->logger  = wc_get_logger();



        $this->id                 = 'payment_highway';
        $this->name               = 'Payment Highway';
        $this->has_fields         = false;
        $this->method_title       = __( 'Payment Highway', 'wc-payment-highway' );
        $this->method_description = __( 'Allows Credit Card Payments via Payment Highway.', 'wc-payment-highway' );
        $this->supports           = array(
            'refunds',
            'subscriptions',
            'products',
            'subscription_cancellation',
            'subscription_reactivation',
            'subscription_suspension',
            'subscription_amount_changes',
            'subscription_payment_method_change', // Subs 1.n compatibility.
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'subscription_date_changes',
            'multiple_subscriptions',
            'tokenization',
            'add_payment_method'
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->load_classes();

        $this->forms = new WC_Payment_Highway_Forms( $this->logger );


        $this->title                            = $this->get_option( 'title' );
        $this->description                      = $this->get_option( 'description' );
        $this->instructions                     = $this->get_option( 'instructions', $this->description );

        $this->accept_diners                    = $this->get_option('accept_diners') === 'yes';
        $this->accept_amex                      = $this->get_option('accept_amex') === 'yes';
        $this->accept_cvc_required              = $this->get_option('accept_cvc_required') === 'yes';
        $this->accept_orders_with_cvc_required  = $this->get_option('accept_orders_with_cvc_required') === 'yes';
        $this->save_all_credit_cards            = $this->get_option('save_all_credit_cards') === 'yes';

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this,'process_admin_options') );

        foreach ( $paymentHighwaySuffixArray as $action ) {
            add_action( $action, array( $this, $action ) );
        }
    }


    public function get_id() {
        return $this->id;
    }

    public function get_forms() {
        return $this->forms;
    }


    private function load_classes() {
        if ( ! class_exists( 'WC_Payment_Highway_Forms' ) ) {
            require_once('class-forms-payment-highway.php' );
        }
    }


    /**
     * @override
     *
     * Builds our payment fields area - including tokenization fields for logged
     * in users, and the actual payment fields.
     * @since 2.6.0
     */
    public function payment_fields() {
        if ( $this->supports( 'tokenization' ) && is_checkout() ) {
            $this->tokenization_script();
            echo "<p>" . $this->description . "</p>";
            $this->saved_payment_methods();;
            $this->save_payment_method_checkbox();
        }
    }

    /**
     * @override
     *
     * Override , so it wont print save to account checkbox
     */
    public function save_payment_method_checkbox() {
        return '';
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = include( 'settings-payment-highway.php' );
    }

    public function check_for_payment_highway_response() {
        if ( isset( $_GET['paymenthighway'] ) ) {

            WC()->payment_gateways();
            do_action( 'check_payment_highway_response' );
        }
    }

    public function paymenthighway_payment_success() {
        global $woocommerce;

        if ( isset( $_GET[ __FUNCTION__ ] ) ) {
            if(isset($_GET['add-card-order-id'])){
                $order_id = $_GET['add-card-order-id'];
            }
            elseif (isset($_GET['sph-order'])){
                $order_id = $_GET['sph-order'];
            }
            else {
                $this->logger->alert("Somehow we did not get any order id.");
                exit;
            }

            $order    = wc_get_order( $order_id );
            if ( $this->forms->verifySignature( $_GET ) ) {
                if(isset($_GET['sph-transaction-id'])) {
                    $order->set_transaction_id($_GET['sph-transaction-id']);
                    $response = $this->forms->commitPayment($_GET['sph-transaction-id'], $_GET['sph-amount'], $_GET['sph-currency']);
                }
                else {
                    $order->set_transaction_id($_GET['sph-tokenization-id']);
                    $response = $this->forms->tokenizeCard($_GET['sph-tokenization-id']);
                }
                $this->handle_payment_response( $response, $order );
            } else {
                $this->redirect_failed_payment( $order, 'Signature mismatch: ' . print_r( $_GET, true ) );
            }
        }
    }

    /**
     * @param $response
     * @param $order WC_Order
     */
    private function handle_revert_response( $response, $order ) {
        $responseObject = json_decode( $response );
        if ( $responseObject->result->code === self::PH_REQUEST_SUCCESSFUL ) {
            $this->logger->info( $response );
            $order->add_order_note('Reverted');
        }
        else {
            $order->add_order_note('Transaction could not be reverted! Check logs!');
            $this->logger->alert("Transaction could not be reverted! Response: " . print_r($responseObject, true));
        }
    }

    /**
     * @param $response
     * @param $order WC_Order
     */
    private function handle_payment_response( $response, $order ) {
        $responseObject = json_decode( $response );
        if ( $responseObject->result->code === self::PH_REQUEST_SUCCESSFUL ) {
            $this->logger->info( $response );
            $this->check_if_recurring_payment_needs_cvc($responseObject, $order);
            $order->payment_complete();
            $order->add_order_note(
                sprintf("Payment complete. Filing code: %s, transaction id: %s, Card type: %s, bin: %s, partial pan: %s ",
                    $responseObject->filing_code, $order->get_transaction_id(), $responseObject->card->type, $responseObject->card->bin, $responseObject->card->partial_pan)
            );
            if($this->is_subscription($order->get_id()) || $this->save_all_credit_cards) {
                if ( get_current_user_id() !== 0 && ! $this->save_card( $responseObject ) ) {
                    wc_add_notice( __( 'Card could not be saved.', 'wc-payment-highway' ), 'notice' );
                }
            }
            wp_redirect( $order->get_checkout_order_received_url() );
        } else {
            $this->redirect_failed_payment( $order, $response, $responseObject );
        }
    }

    /**
     * @param $responseObject
     * @param $order WC_Order
     */
    private function check_if_recurring_payment_needs_cvc($responseObject, $order) {
        if($this->is_subscription($order->get_id()) && $responseObject->card->cvc_required === 'yes' && !$this->accept_orders_with_cvc_required) {
            $order->add_order_note('Recurring payment with card that does not support CVC.');
            wc_add_notice( __( 'Your card does not support recurring payments without CVC. Please use another card.', 'wc-payment-highway' ), 'notice' );
            if(intval($order->get_total()) !== 0) {
                $response = $this->forms->revertPayment($order->get_transaction_id(), null);
                $order->add_order_note('Reverting...');
                $this->handle_revert_response($response, $order);
            }
            $this->redirect_failed_payment( $order, 'Recurring payment with card that does not support CVC.' );
        }
    }

    /**
     * @param $order WC_Order
     * @param $error
     * @param null $responseObject
     */
    private function redirect_failed_payment( $order, $error, $responseObject = null ) {
        global $woocommerce;
        if(!is_null($responseObject) && $responseObject->result->code === self::PH_RESULT_FAILURE) {
            wc_add_notice( __( 'Payment rejected, please try again.', 'wc-payment-highway' ), 'error' );
            $order->update_status( 'failed', __( 'Payment Highway payment rejected', 'wc-payment-highway' ) );
        }
        else{
            wc_add_notice( __( 'Payment failed, please try again.', 'wc-payment-highway' ), 'error' );
            $order->update_status( 'failed', __( 'Payment Highway payment failed', 'wc-payment-highway' ) );
            $this->logger->alert( $error );
        }
        wp_redirect( $woocommerce->cart->get_checkout_url() );
    }

    private function save_card( $responseObject ) {
        $returnValue = false;
        if ( $responseObject->card->cvc_required === "no" || $this->accept_cvc_required ) {
            if ( $this->isTokenAlreadySaved( $responseObject->card_token ) ) {
                return true;
            }
            $token = new WC_Payment_Token_CC();
            // set
            $token->set_token( $responseObject->card_token );
            $token->set_gateway_id( $this->id );
            $token->set_card_type( strtolower( $responseObject->card->type ) );
            $token->set_last4( $responseObject->card->partial_pan );
            $token->set_expiry_month( $responseObject->card->expire_month );
            $token->set_expiry_year( $responseObject->card->expire_year );
            $token->set_user_id( get_current_user_id() );
            $returnValue = $token->save();
        }
        if ( $returnValue ) {
            wc_add_notice( __( 'Card saved.', 'wc-payment-highway' ) );
        }

        return $returnValue;
    }

    private function isTokenAlreadySaved( $token ) {
        $tokens = WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), $this->id );
        /**
         * @var WC_Payment_Token_CC $t
         */
        foreach ( $tokens as $t ) {
            if ( $t->get_token() === $token ) {
                return true;
            }
        }

        return false;
    }

    public function paymenthighway_add_card_failure() {
        global $woocommerce;
        if ( isset( $_GET[ __FUNCTION__ ] ) ) {
            wc_add_notice( __( 'Card could not be saved.', 'wc-payment-highway' ), 'error' );
            $this->logger->alert( print_r( $_GET, true ) );
        }
    }

    public function paymenthighway_add_card_success() {
        global $woocommerce;

        if ( isset( $_GET[ __FUNCTION__ ] ) ) {
            if ( $this->forms->verifySignature( $_GET ) ) {
                $response = $this->forms->tokenizeCard( $_GET['sph-tokenization-id'] );
                $this->logger->info( $response );
                $this->handle_add_card_response( $response );
                $this->redirect_add_card( '', $response );
            }
            else {
                $this->redirect_add_card( '', 'Signature mismatch: ' . print_r( $_GET, true ) );
            }
        }
    }

    private function handle_add_card_response( $response ) {
        $responseObject = json_decode( $response );
        if ( $responseObject->result->code === self::PH_REQUEST_SUCCESSFUL ) {
            if ( $responseObject->card->cvc_required === "no" || $this->accept_cvc_required ) {
                $this->save_card( $responseObject );
                wp_redirect( get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) );
            } else {
                $this->redirect_add_card( __( 'Unfortunately the card does not support payments without CVC2/CVV2 security code.' ), 'Card could not be used without cvc.', 'notice' );
            }
        }
    }

    private function redirect_add_card( $notice, $error, $level = 'error' ) {
        $this->logger->alert( $error );
        wc_add_notice( __( 'Card could not be saved. ' . $notice, 'wc-payment-highway' ), $level );
        wp_redirect( get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) );
    }


    /**
     * Process the payment and return the result
     *
     * @param int $order_id
     *
     * @param bool $must_be_logged_in
     *
     * @return array
     */
    public function process_payment( $order_id, $must_be_logged_in = false ) {
        global $woocommerce;
        if ( $must_be_logged_in && get_current_user_id() === 0 ) {
            wc_add_notice( __( 'You must be logged in.', 'wc-payment-highway' ), 'error' );

            return array(
                'result'   => 'fail',
                'redirect' => $woocommerce->cart->get_checkout_url()
            );
        }
        if ( isset( $_POST[ 'wc-' . $this->id . '-payment-token' ] ) && $_POST[ 'wc-' . $this->id . '-payment-token' ] !== 'new' ) {
            return $this->process_payment_with_token( $order_id );
        }
        $order = new WC_Order( $order_id );
        $order->update_status( 'pending', __( 'Pending Payment Highway payment', 'wc-payment-highway' ) );

        wc_reduce_stock_levels( $order_id );

        if(self::get_ph_amount($order->get_total()) === 0) {
            $redirect = $this->forms->addCardForm(true, $order_id);
        }
        else {
            if (!$this->is_subscription($order_id) && !$this->save_all_credit_cards) {
                $redirect = $this->forms->paymentForm($order_id);
            } else {
                $redirect = $this->forms->addCardAndPaymentForm($order_id);
            }
        }

        return array(
            'result'   => 'success',
            'redirect' => $redirect
        );
    }

    private function process_payment_with_token( $order_id ) {
        global $woocommerce;

        $token_id = wc_clean( $_POST[ 'wc-' . $this->id . '-payment-token' ] );
        $token    = WC_Payment_Tokens::get( $token_id );

        $order = new WC_Order( $order_id );
        $order->update_status( 'pending', __( 'Payment Highway payment failed', 'wc-payment-highway' ) );

        wc_reduce_stock_levels( $order_id );

        $amount = self::get_ph_amount( $order->get_total() );

        $response       = $this->forms->payCitWithToken( $token->get_token(), $order, $amount, get_woocommerce_currency() );
        $responseObject = json_decode( $response );

        if ( $responseObject->result->code !== self::PH_REQUEST_SUCCESSFUL ) {
            if($responseObject->result->code == self::PH_RESULT_SOFT_DECLINE) {
                return $this->process_soft_decline_response($order_id, $responseObject);
            }
            else {
                return $this->process_failure_response($order_id, $responseObject);
            }
        }

        $order->payment_complete();

        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url()
        );
    }

    /**
     * @param $order_id
     * @param $responseObject
     * @param $order WC_Order
     * @return array
     */
    private function process_soft_decline_response($order_id, $responseObject, $order) {
        global $woocommerce;

        $three_d_secure_url = $responseObject->three_d_secure_url;

        $this->logger->debug("Soft decline. 3ds url: " . $three_d_secure_url);

        if(is_null($three_d_secure_url)) {
            return $this->process_failure_response($order_id, $responseObject);
        }

        return array(
            'result'   => 'success',
            'redirect' => $three_d_secure_url
        );
    }

    private function process_failure_response($order_id, $responseObject) {
        global $woocommerce;


        $this->logger->alert( "Error while making debit transaction with token. Order: $order_id, PH Code: " . $responseObject->result->code . ", " . $responseObject->result->message );
        if($responseObject->result->code === self::PH_RESULT_FAILURE) {
            wc_add_notice( __( 'Payment rejected, please try again.', 'wc-payment-highway' ), 'error' );
        }

        return array(
            'result'   => 'fail',
            'redirect' => $woocommerce->cart->get_checkout_url()
        );
    }

    /**
     * @param float $amount
     * @return int
     */
    public static function get_ph_amount($amount) {
        return (int)round($amount * 100, 0);
    }


    /**
     * add_payment_method function.
     *
     * Outputs scripts used for payment
     *
     * @access public
     */
    public function add_payment_method() {
        wp_redirect( $this->forms->addCardForm(), 303 );
    }

    /**
     * Refund a charge
     *
     * @param  int $order_id
     * @param  float $amount
     *
     * @param string $reason
     *
     * @return bool
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->get_transaction_id() ) {
            return false;
        }

        $phAmount = is_null( $amount ) ? $amount : self::get_ph_amount( $amount );
        $this->logger->info( "Revert order: $order_id (TX ID: " . $order->get_transaction_id() . ") amount: $amount, ph-amount: $phAmount" );

        $response       = $this->forms->revertPayment( $order->get_transaction_id(), $phAmount );
        $responseObject = json_decode( $response );
        if ( $responseObject->result->code === self::PH_REQUEST_SUCCESSFUL ) {
            return true;
        } else {
            $this->logger->alert( "Error while making refund for order $order_id. PH Code:" . $responseObject->result->code . ", " . $responseObject->result->message );

            return false;
        }
    }

    /**
     * Get gateway icon.
     *
     * @access public
     * @return string
     */
    public function get_icon() {
        $icon  = '<br />';
        if($this->accept_amex) {
            $icon .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/amex.svg' ) . '" alt="Amex" width="32" />';
        }
        if($this->accept_diners) {
            $icon .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/diners.svg' ) . '" alt="Diners" width="32" />';
        }
        $icon .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/visa.svg' ) . '" alt="Visa" width="32" />';
        $icon .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/mastercard.svg' ) . '" alt="MasterCard" width="32" />';


        return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
    }

    /**
     * Is $order_id a subscription?
     * @param  int  $order_id
     * @return boolean
     */
    protected function is_subscription( $order_id ) {
        return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
    }
}