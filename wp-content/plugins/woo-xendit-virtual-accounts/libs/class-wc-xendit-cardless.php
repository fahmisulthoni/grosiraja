<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Xendit_Cardless extends WC_Payment_Gateway {
    const DEFAULT_CARDLESS_TYPE = 'KREDIVO';
    const DEFAULT_MAX_AMOUNT_30DAYS = 3000000;
    const DEFAULT_MAX_AMOUNT_OTHERS = 30000000;
    const DEFAULT_EXTERNAL_ID_VALUE = 'woocommerce-xendit';

    public function __construct()
    {
        $this->id = 'xendit_kredivo';
        $this->has_fields = true;

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        $this->enabled = $this->get_option('enabled');
        $this->supported_currencies = array(
            'IDR'
        );

        $this->method_type = 'Cardless';
        $this->method_code = 'Kredivo';
        $this->title = !empty($this->get_option('channel_name')) ? $this->get_option('channel_name') : $this->method_code;
        $this->default_description = 'Bayar pesanan dengan Kredivo anda melalui <strong>Xendit</strong>';
        $this->description = !empty($this->get_option('payment_description')) ? nl2br($this->get_option('payment_description')) : $this->default_description;
        $this->verification_token = $this->get_option('verification_token');

        $this->method_title = __('Xendit Kredivo', 'woocommerce-gateway-xendit');
        $this->method_description = sprintf(__('Collect payment from Kredivo on checkout page and get the report realtime on your Xendit Dashboard. <a href="%1$s" target="_blank">Sign In</a> or <a href="%2$s" target="_blank">sign up</a> on Xendit and integrate with <a href="%3$s" target="_blank">your Xendit keys</a>.', 'woocommerce-gateway-xendit'), 'https://dashboard.xendit.co/auth/login', 'https://dashboard.xendit.co/register', 'https://dashboard.xendit.co/settings/developers#api-keys');

        $main_settings = get_option('woocommerce_xendit_gateway_settings');
        $this->developmentmode = $main_settings['developmentmode'];
        $this->secret_key = $this->developmentmode == 'yes' ? $main_settings['secret_key_dev'] : $main_settings['secret_key'];
        $this->publishable_key = $this->developmentmode == 'yes' ? $main_settings['api_key_dev'] : $main_settings['api_key'];
        $this->external_id_format = !empty($main_settings['external_id_format']) ? $main_settings['external_id_format'] : self::DEFAULT_EXTERNAL_ID_VALUE;

        $this->xendit_status = $this->developmentmode == 'yes' ? "[Development]" : "[Production]";
        $this->xendit_callback_url = home_url() . '/?xendit_mode=xendit_cardless_callback';
        $this->success_payment_xendit = $main_settings['success_payment_xendit'];

        $options['secret_api_key'] = $this->secret_key;
        $options['public_api_key'] = $this->publishable_key;
        $this->xenditClass = new WC_Xendit_PG_API($options);

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_filter('woocommerce_available_payment_gateways', array(&$this, 'check_gateway_status'));
        add_filter('woocommerce_payment_complete_order_status', array(&$this, 'update_status_complete'));
    }

    public function init_form_fields()
    {
        $this->form_fields = require(WC_XENDIT_PG_PLUGIN_PATH . '/libs/forms/wc-xendit-cardless-kredivo-settings.php');
    }

    public function admin_options()
    {
?>
        <script>
            jQuery(document).ready(function($) {
                var paymentDescription = $(
                    "#woocommerce_<?= $this->id; ?>_payment_description"
                ).val();
                if (paymentDescription.length > 250) {
                    return swal({
                        text: 'Text is too long, please reduce the message and ensure that the length of the character is less than 250.',
                        buttons: {
                            cancel: 'Cancel'
                        }
                    }).then(function(value) {
                        e.preventDefault();
                    });
                }

                $(".channel-name-format").text(
                    "<?= $this->title ?>");

                $("#woocommerce_<?= $this->id; ?>_channel_name").change(
                    function() {
                        $(".channel-name-format").text($(this).val());
                    });
            });
        </script>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
<?php
    }

    public function payment_fields()
    {
        if ($this->description) {
            $test_description = '';
            if ($this->developmentmode == 'yes') {
                $test_description = ' <strong>TEST MODE</strong> - Real payment will not be detected';
            }

            echo '<p>' . $this->description . '</p>
                <p style="color: red; font-size:80%; margin-top:10px;">' . $test_description . '</p>';
        }

        echo '<fieldset id="wc-' . esc_attr($this->id) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';

        do_action('woocommerce_credit_card_form_start', $this->id);

        // I recommend to use inique IDs, because other gateways could already use #ccNo, #expdate, #cvc
        echo '<div class="form-row form-row-wide">
                <label>Installment <span class="required">*</span></label>
                <select id="xendit_payment_type_kredivo" name="xendit_payment_type_kredivo" autocomplete="off">
                    <option value="30_days">30 days</option>
                    <option value="3_months">3 months</option>
                    <option value="6_months">6 months</option>
                    <option value="12_months">12 months</option>
                </select>
            </div>
            <div class="clear"></div>';

        do_action('woocommerce_credit_card_form_end', $this->id);

        echo '<div class="clear"></div></fieldset>';
    }

    public function validate_fields() {
        $listPaymentType = array("30_days", "3_months", "6_months", "12_months");

        if (empty($_POST['xendit_payment_type_kredivo'])) {
            wc_add_notice('<strong>Installment</strong> is required!', 'error');
            return false;
        } else if (!in_array($_POST['xendit_payment_type_kredivo'], $listPaymentType)) {
            wc_add_notice('<strong>Installment</strong> must be ' . join(", ", $listPaymentType), 'error');
            return false;
        }

        return true;
    }

    public function validate_payment($response)
    {
        global $wpdb, $woocommerce;

        $external_id = $response->external_id;

        $xendit_status = $this->xendit_status;
        
        if(($response->cardless_credit_type === "KREDIVO" && empty($this->verification_token)) || ($this->verification_token != $response->callback_authentication_token)) {
            WC_Xendit_PG_Logger::log("{$xendit_status} verification token not match!", WC_LogDNA_Level::ERROR, true);
            header('HTTP/1.1 401 verification token not match');
            echo "verification token not match"; 
            exit;
        }
        
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
                    WC_Xendit_PG_Logger::log("{$xendit_status} Xendit is live and required valid order id!", WC_LogDNA_Level::ERROR, true);

                    header('HTTP/1.1 400 Invalid Data Received');
                    exit;
                }
            }

            if ($response->transaction_status === 'settlement') {
                WC_Xendit_PG_Logger::log("{$xendit_status} Xendit is {$response->transaction_status}, Proccess Order!");

                $notes = json_encode(
                    array(
                        'transaction_id' => $response->transaction_id,
                        'status' => $response->transaction_status,
                        'payment_type' => $response->payment_type,
                        'paid_amount' => $response->amount,
                    )
                );

                $note = "Xendit Payment Response:" .
                    "{$notes}";

                $order->add_order_note('Xendit payment successful');
                $order->add_order_note($note);

                // Do mark payment as complete
                $order->payment_complete();

                // Reduce stock levels
                $order->reduce_order_stock();

                // Empty cart in action
                $woocommerce->cart->empty_cart();

                WC_Xendit_PG_Logger::log("{$xendit_status} Order #{$order->id} now mark as complete with Xendit!");
            } else if ($response->transaction_status === "deny" || $response->transaction_status === "cancel" || $response->transaction_status === "expire") {
                WC_Xendit_PG_Logger::log("{$xendit_status} Xendit is {$response->transaction_status}, Proccess Order Declined!", WC_LogDNA_Level::ERROR, true);

                $order->update_status('failed');

                $notes = json_encode(
                    array(
                        'transaction_id' => $response->transaction_id,
                        'status' => $response->transaction_status,
                        'payment_type' => $response->payment_type,
                        'paid_amount' => $response->amount,
                    )
                );

                $note = "Xendit Payment Response:" .
                    "{$notes}";

                $order->add_order_note('Xendit payment failed');
                $order->add_order_note($note);

                header('HTTP/1.1 400 Invalid Data Received');
            }

            echo 'Success';
            die;
        } else {
            WC_Xendit_PG_Logger::log("{$xendit_status} Xendit external id check not passed, break!", WC_LogDNA_Level::ERROR, true);

            header('HTTP/1.1 400 Invalid Data Received');
            exit;
        }
    }

    public function get_icon()
    {
        $style = version_compare(WC()->version, '2.6', '>=') ? 'style="margin-left: 0.3em; max-height: 32px;"' : '';
        $file_name = strtolower($this->method_code) . '.png';
        $icon = '<img src="' . plugins_url('assets/images/' . $file_name, WC_XENDIT_PG_MAIN_FILE) . '" alt="Xendit" ' . $style . ' />';

        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }
    
    /* 
    * Execute when click place order
    */
    public function process_payment($order_id) {
        global $woocommerce;
        $order = wc_get_order($order_id);

        $external_id = $this->external_id_format . '-' . $order_id;
        $amount = $order->order_total;
        $payment_type = wc_clean($_POST['xendit_payment_type_kredivo']);
        
        $log_msg = "process_payment(order id) order: ".$order_id."\n\n";
        $log_msg .= "{$this->environment} [".$this->external_id_format ."-". $order_id."]\n\n";
        
        /* Handle credit limit */
        if ($payment_type == "30_days" && $amount > WC_Xendit_Cardless::DEFAULT_MAX_AMOUNT_30DAYS) {
            $this->cancel_order($order, 'Cancelled because amount exceeds credit limit.');
            
            $log_msg .= "cancelled because amount exceeds credit limit. payment_type: ".$payment_type." amount: ".$order_id."max: ".WC_Xendit_Cardless::DEFAULT_MAX_AMOUNT_30DAYS." \n\n";
            
            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);
            
            throw new Exception( sprintf( __(
                'The maximum amount for 30 days installment is %1$s. Please select a longer installment scheme.',
                'woocommerce-gateway-xendit'
            ), wc_price( WC_Xendit_Cardless::DEFAULT_MAX_AMOUNT_30DAYS )) );
        }
        else if ($amount > WC_Xendit_Cardless::DEFAULT_MAX_AMOUNT_OTHERS) {
            $this->cancel_order($order, 'Cancelled because amount exceeds credit limit.');
            
            $log_msg .= "cancelled because amount exceeds credit limit. payment_type: not 30_days, amount: ".$order_id."max: ".WC_Xendit_Cardless::DEFAULT_MAX_AMOUNT_30DAYS." \n\n";
            
            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);
            
            throw new Exception( sprintf( __(
                'The maximum amount for this payment method is %1$s. Please remove one or more item(s) from your cart. <br />' .
                '<a href="%2$s">Your Cart</a>',
                'woocommerce-gateway-xendit'
            ), wc_price( WC_Xendit_Cardless::DEFAULT_MAX_AMOUNT_OTHERS ), wc_get_cart_url()) );
        }

        $items = array();
        foreach ($order->get_items() AS $item_data) {
            // Get an instance of WC_Product object
            $product = $item_data->get_product();

            $item = array();
            $item['id']         = $product->get_id();
            $item['name']       = $product->get_name();
            $item['price']      = $product->get_price();
            $item['type']       = $product->get_type();
            $item['url']        = get_permalink($item->id);
            $item['quantity']   = $item_data->get_quantity();
            
            array_push($items, json_encode(array_map('strval', $item)));
        }

        $customer_details = array();
        $customer_details['first_name'] = $order->billing_first_name;
        $customer_details['last_name']  = $order->billing_last_name;
        $customer_details['email']      = $order->billing_email;
        $customer_details['phone']      = $order->billing_phone;
      
        $billing_address_format = trim($order->billing_address_1."\n".$order->billing_address_2);
        $shipping_address_format = trim($order->shipping_address_1."\n".$order->shipping_address_2);

        $shipping_address = array();
        $shipping_address['first_name']     = $order->shipping_first_name ? $order->shipping_first_name : $order->billing_first_name;
        $shipping_address['last_name']      = $order->shipping_last_name ? $order->shipping_last_name : $order->billing_last_name;
        $shipping_address['address']        = $shipping_address_format ? $shipping_address_format : $billing_address_format;
        $shipping_address['city']           = $order->shipping_city ? $order->shipping_city : $order->billing_city;
        $shipping_address['postal_code']    = $order->shipping_postcode ? $order->shipping_postcode : $order->billing_postcode;
        $shipping_address['phone']          = $order->billing_phone;
        $shipping_address['country_code']   = $order->shipping_country ? $order->shipping_country : $order->billing_country;

        try {
            /*
             * Array with parameters for API interaction
             */
            $args = array(
                'cardless_credit_type' => WC_Xendit_Cardless::DEFAULT_CARDLESS_TYPE,
                'external_id' => $external_id,
                'amount' => floatval($amount),
                'payment_type' => $payment_type,
                'items' => '[' . implode(",", $items) . ']',
                'customer_details' => json_encode($customer_details),
                'shipping_address' => json_encode($shipping_address),
                'redirect_url' => $this->get_return_url($order), //thank you page
                'callback_url' => $this->xendit_callback_url
            );
            $header = array(
                'x-plugin-method' => strtoupper( $this->method_code ),
                'x-plugin-store-name' => get_option('blogname')
            );

            $response = $this->xenditClass->createCardlessPayment($args, $header);

            $log_msg .= "Create cadless payment, args: ".json_encode($args)." header: ".json_encode($header).", response: ".json_encode($response)." \n\n";

            if (isset($response['error_code'])) {
                if ($response['error_code'] == 'DUPLICATE_PAYMENT_ERROR') {
                    $args['external_id'] = $external_id . '_' . uniqid(); //generate a unique external id
                    
                    $log_msg .= "Duplicate payment error\n\n"; 
                    
                    $response = $this->xenditClass->createCardlessPayment($args, $header); //retry with unique id
                    
                    $log_msg .= "Try to recreate create cardless payment with data, args: ".json_encode($args).", header: ".json_encode($header).", response: ".json_encode($response)." \n\n";

                    if (isset($response['error_code'])) {
                        update_post_meta($order_id, 'Xendit_error', esc_attr($response['error_code']));
                        if ($this->developmentmode == 'yes') {
                            WC_Xendit_PG_Logger::log( json_encode($response, JSON_PRETTY_PRINT) );
                        }
                        
                        $log_msg .= "Try to recreate cardless payment failed\n\n";

                        WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);

                        wc_add_notice( $this->get_localized_error_message($response['error_code'], $response['message']), 'error');
                        return;
                    }
                }
                else {
                    update_post_meta($order_id, 'Xendit_error', esc_attr($response['error_code']));
                    if ($this->developmentmode == 'yes') {
                        WC_Xendit_PG_Logger::log( json_encode($response, JSON_PRETTY_PRINT) );
                    }
                    
                    $log_msg .= "Cardless request get error\n\n";
                    
                    wc_add_notice( $this->get_localized_error_message($response['error_code'], $response['message']), 'error');
                    return;
                }
            }
            
            if (isset($response['redirect_url'])) {
                // Set payment pending
                $order->update_status('pending', __('Awaiting Xendit payment', 'xendit'));
                update_post_meta($order_id, 'Xendit_order_id', esc_attr($response['order_id']));
                update_post_meta($order_id, 'Xendit_cardless_url', esc_attr($response['redirect_url']));
                
                $log_msg .= "Process finished\n\n";
                
                WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::INFO, true);
                
                // Redirect to Kredivo page
                return array(
                    'result' => 'success',
                    'redirect' => $response['redirect_url']
                );
            }
            else { //we're still in checkout page
                $log_msg .= "Cardless request get error\n\n";
                
                WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);
                
                wc_add_notice( $this->get_localized_error_message('GENERATE_CHECKOUT_URL_ERROR'), 'error' );
                return;
            }
        } catch ( Exception $e ) {
            wc_add_notice( 'Unexpected error.', 'error' );
            return;
        }
    }

    public function get_localized_error_message($error_code, $message = "") {
        switch ( $error_code ) {
            case 'API_VALIDATION_ERROR':
            case 'MERCHANT_NOT_FOUND':
            case 'DUPLICATE_PAYMENT_ERROR':
                return $message;
            case 'GENERATE_CHECKOUT_URL_ERROR':
                return 'There is a problem connecting to Kredivo / Partner server. Please try again.';
            default:
                return "Failed to pay with Kredivo. Error code: $error_code";
        }
    }

    public function check_gateway_status( $gateways ) {
        global $wpdb, $woocommerce;

        if (is_null($woocommerce->cart)) {
            return $gateways;
        }

        if ( empty($this->secret_key) ) {
            unset($gateways[$this->id]);
            return $gateways;
        }

        if ( empty($this->verification_token) ) {
            unset($gateways[$this->id]);
            return $gateways;
        }

        $amount = WC_Xendit_PG_Helper::get_float_amount($woocommerce->cart->get_cart_total());
        if ($amount > WC_Xendit_Cardless::DEFAULT_MAX_AMOUNT_OTHERS):
            unset($gateways[$this->id]);

            return $gateways;
        endif;

        return $gateways;
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