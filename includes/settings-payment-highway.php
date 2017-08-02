<?php
      
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

return apply_filters('wc_payment_highway_settings', 
    array(
  
        'enabled' => array(
            'title'   => __( 'Enable/Disable', 'wc-payment-highway' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable Payment Highway', 'wc-payment-highway' ),
            'default' => 'yes'
        ),
        
        'title' => array(
            'title'       => __( 'Title', 'wc-payment-highway' ),
            'type'        => 'text',
            'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-payment-highway' ),
            'default'     => __( 'Payment Highway', 'wc-payment-highway' ),
            'desc_tip'    => true,
        ),
        'sph_account' => array(
            'title'       => __( 'SPH Account', 'wc-payment-highway' ),
            'type'        => 'text',
            'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-payment-highway' ),
            'default'     => __( 'test', 'wc-payment-highway' ),
            'desc_tip'    => true,
        ),
        
        'sph_merchant' => array(
            'title'       => __( 'SPH Merchant', 'wc-payment-highway' ),
            'type'        => 'text',
            'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-payment-highway' ),
            'default'     => __( 'test_merchantId', 'wc-payment-highway' ),
            'desc_tip'    => true,
        ),
        
        'sph_url' => array(
            'title'       => __( 'SPH Endpoint URL', 'wc-payment-highway' ),
            'type'        => 'text',
            'description' => __( 'Payment Highway\'s endoint url address', 'wc-payment-highway' ),
            'default'     => __( 'https://v1-hub-staging.sph-test-solinor.com', 'wc-payment-highway' ),
            'desc_tip'    => true,
        ),
        
        'api_key_id' => array(
            'title'       => __( 'API Key ID', 'wc-payment-highway' ),
            'type'        => 'text',
            'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-payment-highway' ),
            'default'     => __( 'testKey', 'wc-payment-highway' ),
            'desc_tip'    => true,
        ),
        
        'api_key_secret' => array(
            'title'       => __( 'API Key Secret', 'wc-payment-highway' ),
            'type'        => 'text',
            'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-payment-highway' ),
            'default'     => __( 'testSecret', 'wc-payment-highway' ),
            'desc_tip'    => true,
        ),
        
        'description' => array(
            'title'       => __( 'Description', 'wc-payment-highway' ),
            'type'        => 'textarea',
            'description' => __( 'Payment method description that the customer will see on your checkout.', 'wc-payment-highway' ),
            'default'     => __( 'You will be redirected to Payment Highway credit card payment form.', 'wc-payment-highway' ),
            'desc_tip'    => true,
        ),
        
        'instructions' => array(
            'title'       => __( 'Instructions', 'wc-payment-highway' ),
            'type'        => 'textarea',
            'description' => __( 'Instructions that will be added to the thank you page and emails.', 'wc-payment-highway' ),
            'default'     => '',
            'desc_tip'    => true,
        ),

        'sph_debug' => array(
            'title'       => __( 'Debug Mode', 'wc-payment-highway' ),
            'label'       => __( 'Enable Payment Highway Debug Mode', 'wc-payment-highway' ),
            'type'        => 'checkbox',
            'description' => __( 'If enabled, all the debug data is shown at templates to all visitors.' ),
            'default'     => 'no',
            'desc_tip'    => true,
        ),

        'sph_locale' => array(
            'title'       => __( 'Payment Highway Locale', 'wc-payment-highway' ),
            'type'        => 'select',
            'class'       => 'wc-enhanced-select',
            'description' => __( 'Language to display in Checkout modal. Finnish will be used by default.', 'wc-payment-highway' ),
            'default'     => 'FI',
            'desc_tip'    => true,
            'options'     => array(
                'FI'   => __( 'Finnish', 'wc-payment-highway' ),
                'EN'   => __( 'English', 'wc-payment-highway' ),
            ),
        ),
        
        'debug_data' => array(
            'title'       => __( 'Debug data', 'wc-payment-highway' ),
            'type'        => 'textarea',
            'default'     => json_encode($wpdb->get_results("SELECT user_id, token FROM `" . $wpdb->prefix . "woocommerce_payment_tokens` WHERE gateway_id = 'payment_highway'")),
        ),  
    )
);