<?php

require 'vendor/autoload.php';

use \Solinor\PaymentHighway\FormBuilder;
use \Solinor\PaymentHighway\PaymentApi;
use Solinor\PaymentHighway\Security\SecureSigner;

class WC_Payment_Highway_Forms {
    private $order_id;
    private $order;
    private $options;
    private $order_url;
    private $signatureKeyId;
    private $signatureSecret;
    private $account;
    private $merchant;
    private $currency;
    private $serviceUrl;
    private $language;
    private $debug;
    private $secureSigner;
    private $paymentApi;


    /**
     * @param array $options
     */
    public function __construct( $options = array() ) {
        $this->options         = get_option( 'woocommerce_payment_highway_settings', $options );
        $this->signatureKeyId  = $this->options['api_key_id'];
        $this->signatureSecret = $this->options['api_key_secret'];
        $this->account         = $this->options['sph_account'];
        $this->merchant        = $this->options['sph_merchant'];
        $this->currency        = get_woocommerce_currency();
        $this->serviceUrl      = $this->options['sph_url'];
        $this->language        = $this->options['sph_locale'];
        $this->debug           = ( $this->options['sph_debug'] === 'yes' ? 1 : 0 );
        $this->secureSigner    = new SecureSigner( $this->signatureKeyId, $this->signatureSecret );
        $this->paymentApi      = new PaymentApi( $this->serviceUrl, $this->signatureKeyId, $this->signatureSecret, $this->account, $this->merchant );
    }


    /**
     * @param $returnUrls array Array of return urls [successUrl, failureUrl, cancelUrl]
     *
     * @return object $form
     */
    private function formBuilder( $returnUrls ) {
        return new FormBuilder( "GET", $this->signatureKeyId, $this->signatureSecret, $this->account,
            $this->merchant, $this->serviceUrl, $returnUrls['successUrl'], $returnUrls['failureUrl'],
            $returnUrls['cancelUrl'], $this->language );
    }

    /**
     * @param string $successSuffix
     *
     * @return array
     */
    private function createCheckoutReturnUrls( $successSuffix = '' ) {
        global $woocommerce;
        $checkout_url = $woocommerce->cart->get_checkout_url();

        $method     = "GET";
        $successUrl = $this->order->get_checkout_order_received_url();
        if ( $successSuffix !== '' ) {
            $successUrl .= '&' . $successSuffix;
        }
        $failureUrl = $checkout_url;
        $cancelUrl  = $checkout_url;

        return array(
            'successUrl' => $successUrl,
            'failureUrl' => $failureUrl,
            'cancelUrl'  => $cancelUrl,
        );
    }

    /**
     * @param string $successSuffix
     *
     * @return array
     */
    private function createAddCardUrls( $successSuffix = '' ) {
        $successUrl = get_permalink();
        if ( $successSuffix !== '' ) {
            $successUrl .= '?' . $successSuffix;
        }
        $failureUrl = $successUrl;
        $cancelUrl  = $successUrl;

        return array(
            'successUrl' => $successUrl,
            'failureUrl' => $failureUrl,
            'cancelUrl'  => $cancelUrl,
        );
    }

    /**
     * @param $order_id string Order id
     *
     * @return string Redirect location
     */
    public function addCardAndPaymentForm( $order_id ) {
        $this->order_id  = $order_id;
        $this->order     = new WC_Order( $this->order_id );
        $this->order_url = WC_Payment_Gateway::get_return_url( $this->order );

        $amount      = intval( $this->order->get_total() * 100 );
        $description = $this->order_id . ' ' . get_the_title( $this->order_id );
        $form        = $this->formBuilder( $this->createCheckoutReturnUrls( "paymenthighway_payment_success" ) )
                            ->generateAddCardAndPaymentParameters( $amount, $this->currency, $this->order_id, $description );

        return $form->getAction() . '?' . http_build_query( $form->getParameters() );
    }


    /**
     * @return string Redirect location
     */
    public function addCardForm() {
        $form = $this->formBuilder( $this->createAddCardUrls( 'paymenthighway_add_card_success' ) )
                     ->generateAddCardParameters();

        return $form->getAction() . '?' . http_build_query( $form->getParameters() );
    }

    /**
     * @param $array
     *
     * @return bool
     */
    public function verifySignature( $array ) {
        try {
            $this->secureSigner->validateFormRedirect( $array );
        } catch ( Exception $e ) {
            return false;
        }

        return true;
    }

    /**
     * @param $transactionId
     * @param $amount
     * @param $currency
     *
     * @return \Httpful\Response
     */
    public function commitPayment( $transactionId, $amount, $currency ) {
        return $this->paymentApi->commitFormTransaction( $transactionId, $amount, $currency );
    }

    /**
     * @param $tokenizeId
     *
     * @return \Httpful\Response
     */
    public function tokenizeCard( $tokenizeId ) {
        return $this->paymentApi->tokenize( $tokenizeId );
    }

}
