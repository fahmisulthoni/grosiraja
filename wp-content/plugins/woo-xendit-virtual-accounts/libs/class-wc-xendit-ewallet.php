<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Xendit_EWallet extends WC_Payment_Gateway {
    const DEFAULT_EXTERNAL_ID_VALUE = 'woocommerce-xendit';
    const DEFAULT_MINIMUM_AMOUNT = 10000;
    const DEFAULT_MAXIMUM_AMOUNT = 10000000;

    public function __construct() {
        $this->supported_currencies = array(
            'IDR'
        );
        $this->enabled = $this->get_option( 'enabled' );

        $main_settings = get_option( 'woocommerce_xendit_gateway_settings' );
        $this->developmentmode = $main_settings['developmentmode'];
        $this->secret_key = $this->developmentmode == 'yes' ? $main_settings['secret_key_dev'] : $main_settings['secret_key'];
        $this->publishable_key = $this->developmentmode == 'yes' ? $main_settings['api_key_dev'] : $main_settings['api_key'];
        $this->external_id_format = !empty($main_settings['external_id_format']) ? $main_settings['external_id_format'] : self::DEFAULT_EXTERNAL_ID_VALUE;

        $this->xendit_status = $this->developmentmode == 'yes' ? "[Development]" : "[Production]";
        $this->xendit_callback_url = home_url() . '/?xendit_mode=xendit_ewallet_callback';
        $this->success_payment_xendit = $main_settings['success_payment_xendit'];

        $options['secret_api_key'] = $this->secret_key;
        $options['public_api_key'] = $this->publishable_key;
        $this->xenditClass = new WC_Xendit_PG_API($options);

        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        add_filter('woocommerce_available_payment_gateways', array(&$this, 'check_gateway_status'));
        add_filter('woocommerce_payment_complete_order_status', array(&$this, 'update_status_complete'));
    }

    public function payment_scripts() {
        WC_Xendit_PG_Logger::log( "WC_Xendit_EWallet::payment_scripts called" );
        if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) && ! is_add_payment_method_page() ) {
            return;
        }

        if ( 'no' === $this->enabled ) {
            return;
        }

        if ( empty( $this->secret_key ) ) {
            return;
        }

        wp_enqueue_script( 'woocommerce_xendit_ewallet', plugins_url( 'assets/js/xendit-ovo.js', WC_XENDIT_PG_MAIN_FILE ), array( 'jquery' ), WC_XENDIT_PG_VERSION, true );
    }

    public function payment_fields() {
        if ( $this->description ) {
            $test_description = '';
            if ( $this->developmentmode == 'yes' ) {
                $test_description = ' <strong>TEST MODE</strong> - Bank account numbers below are for testing. Real payment will not be detected';
            }

            echo '<p>' . $this->description . '</p>
                <p style="color: red; font-size:80%; margin-top:10px;">' . $test_description . '</p>';
        }
    }

    public function process_payment( $order_id ) {
        global $woocommerce;
        $log_msg = "WC_Xendit_EWallet::process_payment($order_id) [".$this->external_id_format . '-' . $order_id."]\n\n";

        $order = wc_get_order( $order_id );
        $total_amount = $order->get_total();

        if ($total_amount < WC_Xendit_EWallet::DEFAULT_MINIMUM_AMOUNT && $this->developmentmode != 'yes') {
            $this->cancel_order($order, 'Cancelled because the amount is below the minimum amount');
            $log_msg .= "Cancelled because amount is below minimum amount. Amount = $total_amount\n\n";
            WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::ERROR, true );

            throw new Exception( sprintf( __(
                'The minimum amount for using this payment is %1$s. Please put more item to reach the minimum amount. <br />' .
                '<a href="%2$s">Your Cart</a>',
                'woocommerce-gateway-xendit'
            ), wc_price( WC_Xendit_EWallet::DEFAULT_MINIMUM_AMOUNT ),  wc_get_cart_url()) );
        }

        if ($total_amount > WC_Xendit_EWallet::DEFAULT_MAXIMUM_AMOUNT) {
            $this->cancel_order($order, 'Cancelled because amount is above maximum amount');
            $log_msg .= "Cancelled because the amount is above the maximum amount. Amount = $total_amount\n\n";
            WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::ERROR, true );

            throw new Exception( sprintf( __(
                'The maximum amount for using this payment is %1$s. Please remove one or more item(s) from your cart. <br />' .
                '<a href="%2$s">Your Cart</a>',
                'woocommerce-gateway-xendit'
            ), wc_price( WC_Xendit_EWallet::DEFAULT_MAXIMUM_AMOUNT ), wc_get_cart_url()) );
        }

        // we need it to get any order details
        try {
            $log_msg .= "Start generate items and customer data\n\n";
            $additional_data = WC_Xendit_PG_Helper::generate_items_and_customer( $order );
            $log_msg .= "Finish generate items and customer data\n\n";
            /*
             * Array with parameters for API interaction
             */
            $external_id = $this->external_id_format . '-' . $order_id;
            $args = array(
                'external_id' => $external_id,
                'amount' => floor($total_amount),
                'ewallet_type' => $this->method_code,
                'items' => isset($additional_data['items']) ? $additional_data['items'] : '',
                'customer' => isset($additional_data['customer']) ? $additional_data['customer'] : '',
                'platform_callback_url' => $this->xendit_callback_url
            );

            switch ($this->method_code) {
                case 'OVO':
                    $args['phone'] = wc_clean( $_POST[$this->id . '_phone'] );
                break;
                case 'DANA':
                    $args['redirect_url'] = get_site_url().'?xendit_ewallet_redirect=true&order_id='.$order_id.'&ewallet_type=DANA';
                break;
                case 'LINKAJA':
                    $args['phone'] = wc_clean( $_POST[$this->id . '_phone'] );
                    $args['redirect_url'] = get_site_url().'?xendit_ewallet_redirect=true&order_id='.$order_id.'&ewallet_type=LINKAJA';
                break;
            }

            $header = array(
                'x-plugin-method' => strtoupper( $this->method_code ),
                'x-plugin-store-name' => get_option('blogname')
            );

            $response = $this->xenditClass->createEwalletPayment($args, $header);

            if ( $response['error_code'] === "DUPLICATE_PAYMENT_REQUEST_ERROR" || $response['error_code'] === "DUPLICATE_PAYMENT_ERROR" || $response['error_code'] === "DUPLICATE_ERROR" ) {
                $args['external_id'] = uniqid().'-'.$external_id;
                
                $response = $this->xenditClass->createEwalletPayment($args, $header);
            }

            if ( isset($response['error_code']) ) {
                $log_msg .= "Ewallet request error. Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n\n";
                update_post_meta($order_id, 'Xendit_error', esc_attr($response['error_code']));

                wc_add_notice( $this->get_localized_error_message( $response['error_code'] ), 'error' );
                WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::ERROR, true );
                return;
            }
            
            update_post_meta($order_id, '_xendit_external_id', $response['external_id']);

            if ( isset($response['checkout_url']) ) { // DANA / LINKAJA redirection
                $log_msg .= "Redirecting to DANA / LINKAJA payment page...\n\n";
                WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::INFO, true );
                return array(
                    'result' => 'success',
                    'redirect' => $response['checkout_url']
                );
            }

            $isSuccessful = false;
            $loopCondition = true;
            $startTime = time();
            while ($loopCondition && (time() - $startTime < 70)) {
                $getEwallet = $this->xenditClass->getEwallet($this->method_code, $external_id);
            
                if($getEwallet['status'] == 'COMPLETED') {
                    $loopCondition = false;
                    $isSuccessful = true;
                }
                
                // for ovo
                if($getEwallet['status'] == 'FAILED') {
                    sleep(10);
                    
                    $getFailure = get_transient('xendit_ewallet_failure_code_'.$order_id);
                    
                    $log_msg .= "Callback failure : ".$getFailure;
                    wc_add_notice( $this->get_localized_error_message( $getFailure ), 'error' );
                    WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::ERROR, true );
                    return;
                }

                sleep(1);
            }

            if(!$isSuccessful) {
                $log_msg .= "Ewallet request error. Response: getEwallet: ".json_encode($getEwallet, JSON_PRETTY_PRINT);
                wc_add_notice( $this->get_localized_error_message( $getEwallet['error_code'] ), 'error' );
                WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::ERROR, true );
                return;
            }

            $log_msg .= "Process finished\n\n";
            WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::INFO, true );

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url( $order )
            );
        } catch ( Exception $e ) {
            $log_msg .= "Exception caught. Error message: " . $e->getMessage() . "\n\n";
            WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::ERROR, true );
            wc_add_notice(  'Unexpected error.', 'error' );
            return;
        }
    }

    public function validate_payment( $response ) {
        global $wpdb, $woocommerce;

        $external_id = $response->external_id;
        $log_msg = "WC_Xendit_EWallet::validate_payment()[$external_id]\n\n";
        $merchant_names = array($this->merchant_name, 'Xendit');

        $xendit_status = $this->xendit_status;

        if ($external_id) {
            $exploded_ext_id = explode("-", $external_id);
            $order_id = end($exploded_ext_id);

            if (!is_numeric($order_id)) {
                $exploded_ext_id = explode("_", $external_id);
                $order_id = end($exploded_ext_id);
            }

            $order = new WC_Order($order_id);

            if ($this->developmentmode != 'yes') {
                $payment_gateway = wc_get_payment_gateway_by_order($order_id);
                if (false === get_post_status($order_id) || strpos($payment_gateway->id, 'xendit')) {
                    $log_msg .= "{$xendit_status} Xendit is live and required valid order id!\n\n";
                    WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);

                    header('HTTP/1.1 400 Invalid Data Received');
                    exit;
                }
            }

            if($response->failure_code) {                    
                set_transient('xendit_ewallet_failure_code_'.$order_id, $response->failure_code, 60);
            }

            //check if order in WC is still pending after payment
            $ewallet_status = $this->xenditClass->getEwalletStatus($response->ewallet_type, $external_id);
            if ('COMPLETED' == $ewallet_status) {
                $log_msg .= "{$xendit_status} Xendit is {$ewallet_status}, Proccess Order!\n\n";

                $notes = json_encode(
                    array(
                        'transaction_id' => $response->external_id,
                        'status' => $ewallet_status,
                        'payment_method' => $response->ewallet_type,
                        'paid_amount' => $response->amount,
                    )
                );

                $note = "Xendit Payment Response:" . "{$notes}";

                $order->add_order_note('Xendit payment successful');
                $order->add_order_note($note);

                // Do mark payment as complete
                $order->payment_complete();

                // Reduce stock levels
                $order->reduce_order_stock();

                // Empty cart in action
                $woocommerce->cart->empty_cart();

                $log_msg .= "{$xendit_status} Payment for Order #{$order->id} now mark as complete with Xendit!\n\n";
                WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::INFO, true);

                //die(json_encode($response, JSON_PRETTY_PRINT)."\n");
                die('SUCCESS');
            } else {
                $log_msg .= "{$xendit_status} Xendit is {$ewallet_status}, Proccess Order Declined!\n\n";
                WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);

                $order->update_status('failed');

                $notes = json_encode(
                    array(
                        'transaction_id' => $response->external_id,
                        'status' => $ewallet_status,
                        'payment_method' => $response->ewallet_type,
                        'paid_amount' => $response->amount,
                    )
                );

                $note = "Xendit Payment Response:" . "{$notes}";

                $order->add_order_note('Xendit payment failed');
                $order->add_order_note($note);

                header('HTTP/1.1 400 Invalid Data Received');
                exit;
            }
        } else {
            $log_msg .= "{$xendit_status} Xendit external id check not passed, break!\n\n";
            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);

            header('HTTP/1.1 400 Invalid Data Received');
            exit;
        }
    }
    
    public function redirect_ewallet( $order_id='', $ewallet_type='DANA' )
    {
        global $wpdb, $woocommerce;

        $order = new WC_Order($order_id);
        $orderData = $order->get_data();
        $external_id = get_post_meta($order_id, '_xendit_external_id', true);;
        $ewallet_status = $this->xenditClass->getEwalletStatus($ewallet_type, $external_id);

        $url = wc_get_checkout_url();
        if($ewallet_status === 'COMPLETED') {
            $url = WC_Payment_Gateway::get_return_url($order);
        }

        wp_safe_redirect($url);
        exit;
    }
    
    public function is_valid_for_use() {
        return in_array( get_woocommerce_currency(), apply_filters(
                'woocommerce_' . $this->id . '_supported_currencies',
                $this->supported_currencies
        ) );
    }

    public function check_gateway_status( $gateways ) {
        global $wpdb, $woocommerce;

        if (is_null($woocommerce->cart)) {
            return $gateways;
        }

        if ( $this->secret_key == "" ) {
            unset($gateways[$this->id]);
            WC_Xendit_PG_Logger::log( "Gateway unset because API key is not set", WC_LogDNA_Level::INFO, true );

            return $gateways;
        }

        $amount = WC_Xendit_PG_Helper::get_float_amount($woocommerce->cart->get_cart_total());
        if ($amount > WC_Xendit_EWallet::DEFAULT_MAXIMUM_AMOUNT) {
            unset($gateways[$this->id]);

            WC_Xendit_PG_Logger::log(
                "Gateway unset because amount: $amount is above maximum amount: " . WC_Xendit_EWallet::DEFAULT_MAXIMUM_AMOUNT,
                WC_LogDNA_Level::INFO,
                true
            );

            return $gateways;
        }

        if (!$this->is_valid_for_use()) {
            unset($gateways[$this->id]);

            return $gateways;
        }

        return $gateways;
    }

    public function get_localized_error_message( $error_code ) {
        switch ( $error_code ) {
            case 'USER_DID_NOT_AUTHORIZE_THE_PAYMENT':
                return 'Please complete the payment request within 60 seconds.';
            case 'USER_DECLINED_THE_TRANSACTION':
                return 'You rejected the payment request, please try again when needed.';
            case 'PHONE_NUMBER_NOT_REGISTERED':
                return 'Your number is not registered in '.$this->method_code.', please register first or contact '.$this->method_code.' Customer Service.';
            case 'EXTERNAL_ERROR':
                return 'There is a technical issue in '.$this->method_code.', please contact the merchant to solve this issue.';
            case 'SENDING_TRANSACTION_ERROR':
                return 'Your transaction is not sent to '.$this->method_code.', please try again.';
            case 'EWALLET_APP_UNREACHABLE':
                return 'Do you have '.$this->method_code.' app on your phone? Please check your '.$this->method_code.' app on your phone and try again.';
            case 'REQUEST_FORBIDDEN_ERROR':
                return 'Your merchant disable '.$this->method_code.' payment from his side, please contact your merchant to re-enable it
                    before trying it again.';
            case 'DEVELOPMENT_MODE_PAYMENT_ACKNOWLEDGED':
                return 'Development mode detected. Please refer to
                    <a href=\'https://docs.xendit.co/en/testing-payments.html\'>this docs</a> for successful payment
                    simulation';
            case 'GENERATE_CHECKOUT_TOKEN_ERROR':
                return 'The creation of LinkAja payment has failed, please try again in a minute.';
            case 'SERVER_ERROR':
                return 'Currently the payment that you requested can not be processed, please inform the merchant to contact us';
            default:
                return "Failed to pay with eWallet. Error code: $error_code";
        }
    }

    public function get_icon() {
        $style = version_compare( WC()->version, '2.6', '>=' ) ? 'style="margin-left: 0.3em; max-height: 23px;"' : '';
        $file_name = strtolower( $this->method_code ) . '.png';
        $icon = '<img src="' . plugins_url('assets/images/' . $file_name , WC_XENDIT_PG_MAIN_FILE) . '" alt="Xendit" ' . $style . ' />';

        return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
    }

    public function update_status_complete($order_id)
    {
        global $wpdb, $woocommerce;

        $order = new WC_Order($order_id);

        return $this->success_payment_xendit;
    }

    private function cancel_order($order, $note) {
        $order->update_status('wc-cancelled');
        $order->add_order_note($note);
    }
}