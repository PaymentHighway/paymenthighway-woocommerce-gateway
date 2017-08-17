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
    public $accept_cvc_required;
    public $forms;
    public $subscriptions;
    private $accept_diners;
    private $accept_amex;

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


        $this->title               = $this->get_option( 'title' );
        $this->description         = $this->get_option( 'description' );
        $this->instructions        = $this->get_option( 'instructions', $this->description );
        $this->accept_cvc_required = $this->get_option( 'accept_cvc_required' ) === 'yes' ? true : false;

        $this->accept_diners       = $this->get_option('accept_diners') === 'yes' ? true : false;
        $this->accept_amex         = $this->get_option('accept_amex') === 'yes' ? true : false;

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
     * Override form, so it wont print credit card form
     */
    public function form() {
        return '';
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
            $order_id = $_GET['sph-order'];
            $order    = wc_get_order( $order_id );
            if ( $this->forms->verifySignature( $_GET ) ) {
                $response = $this->forms->commitPayment( $_GET['sph-transaction-id'], $_GET['sph-amount'], $_GET['sph-currency'] );
                $order->set_transaction_id( $_GET['sph-transaction-id'] );
                $this->handle_payment_response( $response, $order );
            } else {
                $this->redirect_failed_payment( $order, 'Signature mismatch: ' . print_r( $_GET, true ) );
            }
        }
    }

    private function handle_payment_response( $response, $order ) {
        $responseObject = json_decode( $response );
        if ( $responseObject->result->code === 100 ) {
            $this->logger->info( $response );
            $order->payment_complete();
            if ( get_current_user_id() !== 0 && ! $this->save_card( $responseObject ) ) {
                wc_add_notice( __( 'Card could not be saved.', 'wc-payment-highway' ), 'notice' );
            }
            wp_redirect( $order->get_checkout_order_received_url() );
            exit;
        } else {
            $this->redirect_failed_payment( $order, $response, $responseObject );
        }
    }

    private function redirect_failed_payment( $order, $error, $responseObject = null ) {
        global $woocommerce;
        if(!is_null($responseObject) && $responseObject->result->code === 200) {
            wc_add_notice( __( 'Payment rejected, please try again.', 'wc-payment-highway' ), 'error' );
            $order->update_status( 'failed', __( 'Payment Highway payment rejected', 'wc-payment-highway' ) );
        }
        else{
            wc_add_notice( __( 'Payment failed, please try again.', 'wc-payment-highway' ), 'error' );
            $order->update_status( 'failed', __( 'Payment Highway payment failed', 'wc-payment-highway' ) );
            $this->logger->alert( $error );
        }
        wp_redirect( $woocommerce->cart->get_checkout_url() );
        exit;
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
        if ( $responseObject->result->code === 100 ) {
            if ( $responseObject->card->cvc_required === "no" || $this->accept_cvc_required ) {
                $this->save_card( $responseObject );
                wp_redirect( get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) );
                exit;
            } else {
                $this->redirect_add_card( __( 'Unfortunately the card does not support payments without CVC2/CVV2 security code.' ), 'Card could not be used without cvc.', 'notice' );
            }
        }
    }

    private function redirect_add_card( $notice, $error, $level = 'error' ) {
        $this->logger->alert( $error );
        wc_add_notice( __( 'Card could not be saved. ' . $notice, 'wc-payment-highway' ), $level );
        wp_redirect( get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) );
        exit;
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
        $order->update_status( 'pending payment', __( 'Payment Highway payment failed', 'wc-payment-highway' ) );

        wc_reduce_stock_levels( $order_id );

        return array(
            'result'   => 'success',
            'redirect' => $this->forms->addCardAndPaymentForm( $order_id )
        );
    }

    private function process_payment_with_token( $order_id ) {
        global $woocommerce;

        $token_id = wc_clean( $_POST[ 'wc-' . $this->id . '-payment-token' ] );
        $token    = WC_Payment_Tokens::get( $token_id );

        $order = new WC_Order( $order_id );
        $order->update_status( 'pending payment', __( 'Payment Highway payment failed', 'wc-payment-highway' ) );

        wc_reduce_stock_levels( $order_id );

        $amount = intval( $order->get_total() * 100 );

        $response       = $this->forms->payWithToken( $token->get_token(), $order, $amount, get_woocommerce_currency() );
        $responseObject = json_decode( $response );

        if ( $responseObject->result->code !== 100 ) {
            $this->logger->alert( "Error while making debit transaction with token. Order: $order_id, PH Code: " . $responseObject->result->code . ", " . $responseObject->result->message );
            if($responseObject->result->code === 200) {
                wc_add_notice( __( 'Payment rejected, please try again.', 'wc-payment-highway' ), 'error' );
            }

            return array(
                'result'   => 'fail',
                'redirect' => $woocommerce->cart->get_checkout_url()
            );
        }

        $order->payment_complete();

        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_order_received_url()
        );
    }


    /**
     * add_payment_method function.
     *
     * Outputs scripts used for payment
     *
     * @access public
     */
    public function add_payment_method() {
        wp_redirect( $this->forms->addCardForm( $this->accept_cvc_required ), 303 );
        exit;
    }

    /**
     * Refund a charge
     *
     * @param  int $order_id
     * @param  float $amount
     *
     * @return bool
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->get_transaction_id() ) {
            return false;
        }

        $phAmount = is_null( $amount ) ? $amount : ( $amount * 100 );
        $this->logger->info( "Revert order: $order_id (TX ID: " . $order->get_transaction_id() . ") amount: $amount, ph-amount: $phAmount" );

        $response       = $this->forms->revertPayment( $order->get_transaction_id(), $phAmount );
        $responseObject = json_decode( $response );
        if ( $responseObject->result->code === 100 ) {
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
}