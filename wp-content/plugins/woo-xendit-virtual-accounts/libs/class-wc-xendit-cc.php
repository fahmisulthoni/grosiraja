<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * WC_Xendit_CC class.
 *
 * @extends WC_Payment_Gateway_CC
 */
class WC_Xendit_CC extends WC_Payment_Gateway_CC
{
    const DEFAULT_MINIMUM_AMOUNT = 10000;
    const DEFAULT_MAXIMUM_AMOUNT = 200000000;
    const DEFAULT_EXTERNAL_ID_VALUE = 'woocommerce-xendit';

    /**
     * Should we capture Credit cards
     *
     * @var bool
     */
    public $capture;

    /**
     * Alternate credit card statement name
     *
     * @var bool
     */
    public $statement_descriptor;

    /**
     * Checkout enabled
     *
     * @var bool
     */
    public $xendit_checkout;

    /**
     * Checkout Locale
     *
     * @var string
     */
    public $xendit_checkout_locale;

    /**
     * Credit card image
     *
     * @var string
     */
    public $xendit_checkout_image;

    /**
     * Should we store the users credit cards?
     *
     * @var bool
     */
    public $saved_cards;

    /**
     * API access secret key
     *
     * @var string
     */
    public $secret_key;

    /**
     * Api access publishable key
     *
     * @var string
     */
    public $publishable_key;

    /**
     * Is test mode active?
     *
     * @var bool
     */
    public $testmode;
    
    /* 
    * environment
    */
    public $environment='';
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->id                   = 'xendit_cc';
        $this->method_title         = __('Xendit', 'woocommerce-gateway-xendit');
        $this->method_description   = sprintf(__('Collect payment from Credit Cards on checkout page and get the report realtime on your Xendit Dashboard. <a href="%1$s" target="_blank">Sign In</a> or <a href="%2$s" target="_blank">sign up</a> on Xendit and integrate with <a href="%3$s" target="_blank">your Xendit keys</a>.', 'woocommerce-gateway-xendit'), 'https://dashboard.xendit.co/auth/login', 'https://dashboard.xendit.co/register', 'https://dashboard.xendit.co/settings/developers#api-keys');
        $this->has_fields           = true;
        $this->view_transaction_url = 'https://dashboard.xendit.co/dashboard/credit_cards';
        $this->supports             = array(
            'subscriptions',
            'products',
            'refunds',
            'subscription_cancellation',
            'subscription_reactivation',
            'subscription_suspension',
            'subscription_amount_changes',
            'subscription_payment_method_change', // Subs 1.n compatibility.
            'subscription_payment_method_change_admin',
            'subscription_date_changes',
            'multiple_subscriptions',
        );
        $this->supported_currencies = array(
            'IDR'
        );

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Get setting values.
        $this->default_title           = 'Credit Card (Xendit)';
        $this->title                   = !empty($this->get_option('channel_name')) ? $this->get_option('channel_name') : $this->default_title;
        $this->default_description     = 'Pay with your credit card via xendit.';
        $this->description             = !empty($this->get_option('payment_description')) ? nl2br($this->get_option('payment_description')) : $this->default_description;
        
        $main_settings = get_option( 'woocommerce_xendit_gateway_settings' );

        $this->developmentmode         = $main_settings['developmentmode'];
        $this->testmode                = 'yes' === $this->developmentmode;
        $this->environment             = $this->testmode ? 'development' : 'production';
        $this->capture                 = true;
        $this->statement_descriptor    = $this->get_option('statement_descriptor');
        $this->xendit_checkout         = 'yes' === $this->get_option('xendit_checkout');
        $this->xendit_checkout_locale  = $this->get_option('xendit_checkout_locale');
        $this->xendit_checkout_image   = '';
        $this->saved_cards             = 'yes' === $this->get_option('saved_cards');
        $this->secret_key              = $this->testmode ? $main_settings['secret_key_dev'] : $main_settings['secret_key'];
        $this->publishable_key         = $this->testmode ? $main_settings['api_key_dev'] : $main_settings['api_key'];
        $this->external_id_format      = !empty($main_settings['external_id_format']) ? $main_settings['external_id_format'] : self::DEFAULT_EXTERNAL_ID_VALUE;
        $this->xendit_status           = $this->developmentmode == 'yes' ? "[Development]" : "[Production]";
        $this->xendit_callback_url     = home_url() . '/?xendit_mode=xendit_cc_callback';
        $this->success_payment_xendit  = $main_settings['success_payment_xendit'];

        if ($this->xendit_checkout) {
            $this->order_button_text = __('Continue to payment', 'woocommerce-gateway-xendit');
        }

        if ($this->testmode) {
            $this->description .= '<br/>' . sprintf(__('TEST MODE. Try card "4000000000000002" with any CVN and future expiration date, or see <a href="%s">Xendit Docs</a> for more test cases.', 'woocommerce-gateway-xendit'), 'https://dashboard.xendit.co/docs/');
            $this->description  = trim($this->description);
        }

        $options['secret_api_key'] = $this->secret_key;
        $options['public_api_key'] = $this->publishable_key;
        $this->xenditClass = new WC_Xendit_PG_API($options);

        // Hooks.
        add_action('wp_enqueue_scripts', array( $this, 'payment_scripts' ));
        add_action('admin_enqueue_scripts', array( $this, 'admin_scripts' ));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
        add_action( 'woocommerce_checkout_billing', array( $this, 'show_checkout_error' ), 10, 0 );
        add_filter('woocommerce_available_payment_gateways', array(&$this, 'xendit_status_payment_gateways'));
        add_filter('woocommerce_payment_complete_order_status', array(&$this, 'update_status_complete'));
        add_filter('woocommerce_thankyou', array(&$this, 'update_order_status'));

        wp_register_script('sweetalert', 'https://unpkg.com/sweetalert@2.1.2/dist/sweetalert.min.js', null, null, true);
        wp_enqueue_script('sweetalert');
    }

    /**
     * Get_icon function. This is called by WC_Payment_Gateway_CC when displaying payment option
     * on checkout page.
     *
     * @access public
     * @return string
     */
    public function get_icon()
    {
        $style = version_compare(WC()->version, '2.6', '>=') ? 'style="margin-left: 0.3em; max-width: 80px;"' : '';

        $icon  = '<img src="' . plugins_url('assets/images/cc.png' , WC_XENDIT_PG_MAIN_FILE) . '" alt="Xendit" ' . $style . ' />';
        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }

    /**
     * Render admin settings HTML
     * 
     * Host some PHP reliant JS to make the form dynamic
     */
    public function admin_options()
    {
        ?>
        <script>
        jQuery(document).ready(function ($) {
            var paymentDescription = $(
                    "#woocommerce_<?=$this->id; ?>_payment_description"
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
                "<?=$this->title?>");

            $("#woocommerce_<?=$this->id; ?>_channel_name").change(
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

    /**
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields()
    {
        $this->form_fields = require( WC_XENDIT_PG_PLUGIN_PATH . '/libs/forms/wc-xendit-cc-settings.php' );
    }

    /**
     * Payment form on checkout page. This is called by WC_Payment_Gateway_CC when displaying
     * payment form on checkout page.
     */
    public function payment_fields()
    {
        $user                 = wp_get_current_user();
        $display_tokenization = $this->supports('tokenization') && is_checkout();
        $total                = WC()->cart->total;

        if ($user->ID) {
            $user_email = get_user_meta($user->ID, 'billing_email', true);
            $user_email = $user_email ? $user_email : $user->user_email;
        } else {
            $user_email = '';
        }

        echo '<div
			id="xendit-payment-data"
			data-description=""
			data-email="' . esc_attr($user_email) . '"
			data-amount="' . esc_attr($total) . '"
			data-name="' . esc_attr($this->statement_descriptor) . '"
			data-currency="' . esc_attr(strtolower(get_woocommerce_currency())) . '"
			data-locale="' . esc_attr('en') . '"
			data-image="' . esc_attr($this->xendit_checkout_image) . '"
			data-allow-remember-me="' . esc_attr($this->saved_cards ? 'true' : 'false') . '">';

        if ($this->description) {
            echo apply_filters('wc_xendit_description', wpautop(wp_kses_post($this->description)));
        }

        if ($display_tokenization) {
            /**
             * This loads WC_Payment_Gateway tokenization script, which enqueues script to update
             * payment form.
             */
            $this->tokenization_script();
        }

        // Load the fields. Source: https://woocommerce.wp-a2z.org/oik_api/wc_payment_gateway_ccform/
        $this->form();
        echo '</div>';
    }

    /**
     * Localize Xendit messages based on code
     *
     * @since 3.0.6
     * @version 3.0.6
     * @return array
     */
    public function get_localized_messages()
    {
        return apply_filters('wc_xendit_localized_messages', array(
            'invalid_number'            => __('The card number that you entered is not Visa/Master Card/JCB, please provide a card number that is supported and try again.', 'woocommerce-gateway-xendit'),
            'invalid_expiry'            => __('The card expiry that you entered does not meet the expected format. Please enter 2 digits of the month (MM) and last 2 digits of the year (YY) and try again.', 'woocommerce-gateway-xendit'),
            'invalid_cvc'               => __('The CVC that you entered is less than 3 digits. Please enter the correct value and try again.', 'woocommerce-gateway-xendit'),
            'incorrect_number'          => __('The card number that you entered must be 16 digits long, please re-enter the correct card number and try again.', 'woocommerce-gateway-xendit'),
            'missing_card_information'  => __('Please enter your {missing_fields}, then try again.', 'woocommerce-gateway-xendit'),
        ));
    }

    /**
     * Load admin scripts.
     *
     * @since 3.1.0
     * @version 3.1.0
     */
    public function admin_scripts()
    {
        if ('woocommerce_page_wc-settings' !== get_current_screen()->id) {
            return;
        }

        wp_enqueue_script('woocommerce_xendit_gateway_admin', plugins_url('assets/js/xendit-cc-admin.js', WC_XENDIT_PG_MAIN_FILE), array(), WC_XENDIT_PG_VERSION, true);

        $xendit_admin_params = array(
            'localized_messages' => array(
                'not_valid_live_key_msg' => __('This is not a valid live key. Live keys start with "x".', 'woocommerce-gateway-xendit'),
                'not_valid_test_key_msg' => __('This is not a valid test key. Test keys start with "x".', 'woocommerce-gateway-xendit'),
                're_verify_button_text'  => __('Re-verify Domain', 'woocommerce-gateway-xendit'),
                'missing_secret_key'     => __('Missing Secret Key. Please set the secret key field above and re-try.', 'woocommerce-gateway-xendit'),
            ),
            'ajaxurl'            => admin_url('admin-ajax.php')
        );

        wp_localize_script('woocommerce_xendit_gateway_admin', 'wc_xendit_admin_params', apply_filters('wc_xendit_admin_params', $xendit_admin_params));
    }

    /**
     * payment_scripts function.
     *
     * Outputs scripts used for xendit payment
     *
     * @access public
     */
    public function payment_scripts()
    {
        WC_Xendit_PG_Logger::log("WC_Xendit_CC::payment_scripts called");

        if (! is_cart() && ! is_checkout() && ! isset($_GET['pay_for_order'])) {
            return;
        }

        echo '<script>var total = 5000</script>';

        wp_enqueue_script('xendit', 'https://js.xendit.co/v1/xendit.min.js', '', WC_XENDIT_PG_VERSION, true);
        wp_enqueue_script('woocommerce_xendit_cc', plugins_url('assets/js/xendit.js', WC_XENDIT_PG_MAIN_FILE), array( 'jquery', 'xendit' ), WC_XENDIT_PG_VERSION, true);

        $xendit_params = array(
            'key' => $this->publishable_key
        );

        // If we're on the pay page we need to pass xendit.js the address of the order.
        // TODO: implement direct payments from the order
        if (isset($_GET['pay_for_order']) && 'true' === $_GET['pay_for_order']) {
            $order_id = wc_get_order_id_by_order_key(urldecode($_GET['key']));
            $order    = wc_get_order($order_id);

            $xendit_params['billing_first_name'] = $order->get_billing_first_name();
            $xendit_params['billing_last_name']  = $order->get_billing_last_name();
            $xendit_params['billing_address_1']  = $order->get_billing_address_1();
            $xendit_params['billing_address_2']  = $order->get_billing_address_2();
            $xendit_params['billing_state']      = $order->get_billing_state();
            $xendit_params['billing_city']       = $order->get_billing_city();
            $xendit_params['billing_postcode']   = $order->get_billing_postcode();
            $xendit_params['billing_country']    = $order->get_billing_country();
            $xendit_params['amount'] 			 = $order->get_total() * 100;
        }

        $cc_settings = $this->get_cc_settings();

        $xendit_params['can_use_dynamic_3ds'] = $cc_settings['can_use_dynamic_3ds'];

        // merge localized messages to be use in JS
        $xendit_params = array_merge($xendit_params, $this->get_localized_messages());

        wp_localize_script('woocommerce_xendit_cc', 'wc_xendit_params', apply_filters('wc_xendit_params', $xendit_params));
    }

    /**
     * Generate the request for the payment.
     * @param  WC_Order $order
     * @param  object $source
     * @return array()
     */
    protected function generate_payment_request($order, $xendit_token, $auth_id = null, $duplicated = false, $is_recurring = null)
    {
        $amount = $order->get_total();
        $token_id = isset($_POST['xendit_token']) ? wc_clean($_POST['xendit_token']) : $xendit_token;

        //TODO: Find out how can we pass CVN on redirected flow
        $cvn = isset($_POST['card_cvn']) ? wc_clean($_POST['card_cvn']) : null;

        $main_settings = get_option( 'woocommerce_xendit_gateway_settings' );
        $default_external_id = $this->external_id_format . '-' . $order->get_id();
        $external_id = $duplicated ? $default_external_id . '-' . uniqid() : $default_external_id;
        $additional_data = WC_Xendit_PG_Helper::generate_items_and_customer( $order );

        $post_data                				= array();
        $post_data['amount']      				= $amount;
        $post_data['token_id']    				= $token_id;
        $post_data['authentication_id']		    = $auth_id;
        $post_data['card_cvn']					= $cvn;
        $post_data['external_id'] 				= $external_id;
        $post_data['store_name']				= get_option('blogname');
        $post_data['items']                     = isset($additional_data['items']) ? $additional_data['items'] : '';
        $post_data['customer']                  = $this->get_customer_details($order);

        if ( !is_null($is_recurring) ) {
            $post_data['is_recurring']          = $is_recurring;
        }

        return $post_data;
    }

    /**
     * Get payment source. This can be a new token or existing token.
     *
     * @throws Exception When card was not added or for and invalid card.
     * @return object
     */
    protected function get_source()
    {
        $xendit_source   = false;
        $token_id        = false;

        // New CC info was entered and we have a new token to process
        if (isset($_POST['xendit_token'])) {
            WC_Xendit_PG_Logger::log('xendit_token available ' . print_r($_POST['xendit_token'], true));

            $xendit_token     = wc_clean($_POST['xendit_token']);
            // Not saving token, so don't define customer either.
            $xendit_source   = $xendit_token;
        } elseif (isset($_POST['wc-xendit-payment-token']) && 'new' !== $_POST['wc-xendit-payment-token']) {
            // Use an EXISTING multiple use token, and then process the payment
            WC_Xendit_PG_Logger::log('wc-xendit-payment-token available');
            $token_id = wc_clean($_POST['wc-xendit-payment-token']);
            $token    = WC_Payment_Tokens::get($token_id);

            // associates payment token with WP user_id
            if (! $token || $token->get_user_id() !== get_current_user_id()) {
                WC()->session->set('refresh_totals', true);
                throw new Exception(__('Invalid payment method. Please input a new card number.', 'woocommerce-gateway-xendit'));
            }

            $xendit_source = $token->get_token();
        }

        return (object) array(
            'token_id' => $token_id,
            'source'   => $xendit_source,
        );
    }

    /**
     * Get payment source from an order. This could be used in the future for
     * a subscription as an example, therefore using the current user ID would
     * not work - the customer won't be logged in :)
     *
     * Not using 2.6 tokens for this part since we need a customer AND a card
     * token, and not just one.
     *
     * @param object $order
     * @return object
     */
    protected function get_order_source($order = null)
    {
        WC_Xendit_PG_Logger::log('WC_Xendit_CC::get_order_source');

        $xendit_source   = false;
        $token_id        = false;

        if ($order) {
            $order_id = version_compare(WC_VERSION, '3.0.0', '<') ? $order->id : $order->get_id();

            if ($meta_value = get_post_meta($order_id, '_xendit_card_id', true)) {
                $xendit_source = $meta_value;
            }
        }

        return (object) array(
            'token_id' => $token_id,
            'source'   => $xendit_source,
        );
    }

    /**
     * Process the payment.
     *
     * NOTE 2019/03/22: The key to have 3DS after order creation is calling it after this is called.
     * Currently still can't do it somehow. Need to dig deeper on this!
     *
     * @param int  $order_id Reference.
     * @param bool $retry Should we retry on fail.
     *
     * @throws Exception If payment will not be accepted.
     *
     * @return array|void
     */
    public function process_payment($order_id, $retry = true)
    {
        $log_msg = "process_payment(order_id, retry[boolean])\n\n";
        $log_msg .= "{$this->environment} [".$this->external_id_format ."-". $order_id."]\n\n";
        $log_msg .= "WC_Xendit_CC::process_payment order_id ==> $order_id \n\n";
        
        $cc_settings = $this->get_cc_settings();
        $log_msg .= "CC settings: " . print_r($cc_settings, true) . "\n\n";
        
        try {
            $order  = wc_get_order($order_id);
            
            $log_msg .= "get woocommerce order, order: ".$order."\n\n";
            
            if ($order->get_total() < WC_Xendit_CC::DEFAULT_MINIMUM_AMOUNT) {
                $this->cancel_order($order, 'Cancelled because amount is below minimum amount');
                
                $log_msg .= "Cancelled because amount is below minimum amount, total order: ".$order->get_total().", minimum amount: ".WC_Xendit_CC::DEFAULT_MINIMUM_AMOUNT."\n\n";
                
                throw new Exception(sprintf(__(
                    'The minimum amount for using this payment is %1$s. Please put more item to reach the minimum amount. <br />' .
                        '<a href="%2$s">Your Cart</a>',
                    'woocommerce-gateway-xendit'
                ), wc_price(WC_Xendit_CC::DEFAULT_MINIMUM_AMOUNT), wc_get_cart_url()));
            }

            if ($order->get_total() > WC_Xendit_CC::DEFAULT_MAXIMUM_AMOUNT) {
                $this->cancel_order($order, 'Cancelled because amount is above maximum amount');
                
                $log_msg .= "Cancelled because amount is above maximum amount, total order: ".$order->get_total().", maximum amount: ".WC_Xendit_CC::DEFAULT_MAXIMUM_AMOUNT."\n\n";
                
                throw new Exception(sprintf(__(
                    'The maximum amount for using this payment is %1$s. Please remove one or more item(s) from your cart. <br />' .
                        '<a href="%2$s">Your Cart</a>',
                    'woocommerce-gateway-xendit'
                ), wc_price(WC_Xendit_CC::DEFAULT_MAXIMUM_AMOUNT), wc_get_cart_url()));
            }

            // Get token.
            $source = $this->get_source();

            if (empty($source->source)) {
                $error_msg = __('Please enter your card details to make a payment.', 'woocommerce-gateway-xendit');
                $error_msg .= ' ' . __('Developers: Please make sure that you are including jQuery and there are no JavaScript errors on the page.', 'woocommerce-gateway-xendit');
                
                $log_msg .= "ERROR: Empty token for order ID \n\n";
                
                throw new Exception($error_msg);
            }

            // Store source to order meta.
            $this->save_source($order, $source);
            
            $log_msg .= "Successful store source to order meta, save_source(order, source), source: ".json_encode($source)." \n\n";

            // Result from Xendit API request.
            $response = null;

            // Handle payment.
            $log_msg .= "Begin processing payment for order $order_id for the amount of {$order->get_total()}\n\n";
            
            if (isset($_POST['wc-xendit-payment-token']) && 'new' !== $_POST['wc-xendit-payment-token']) {
                $token_id = wc_clean($_POST['wc-xendit-payment-token']);
                $token    = WC_Payment_Tokens::get($source->source);

                $xendit_token = $token->get_token();
            }

            if (isset($_POST['xendit_token'])) {
                $xendit_token = $_POST['xendit_token'];
            }

            if(empty($cc_settings["should_authenticate"])) {
                // if should_authenticate equal to false
                if(!empty($cc_settings["can_use_dynamic_3ds"])) {
                    // if can_use_dynamic_3ds equal to true, the payment using 3ds recomendation
                    $log_msg .= "The payment using 3ds recomendation\n\n";
                    WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::INFO, true );

                    return $this->process_payment_3ds_recommendation($order, $xendit_token);
                } else {
                    $log_msg .= "user pay without authenticate\n\n";
                    WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::INFO, true );

                    return $this->process_payment_without_authenticate($order, $xendit_token);
                }
            } else {
                // if should_authenticate equal to true, the payment must using 3ds
                $log_msg .= "The payment using 3ds\n\n";
                WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::INFO, true );

                return $this->process_payment_must_3ds($order, $xendit_token);
            }
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');

            if ($order->has_status(array( 'pending', 'failed' ))) {
                $this->send_failed_order_email($order_id);
            }

            $log_msg .= "Exception caught. Error message: " . $e->getMessage() . "\n\n";
            WC_Xendit_PG_Logger::log( $log_msg, WC_LogDNA_Level::ERROR, true );
            return;
        }
    }

    /**
     * Payment flow using 3DS recommendation feature.
     * 
     * @param WC_Order $order
     * @param string $xendit_token
     */
    private function process_payment_3ds_recommendation($order, $xendit_token)
    {
        $log_msg = "process_payment_3ds_recommendation(order, xendit_token) order: ".$order." xendit_token: ".$xendit_token."\n\n";
        $log_msg .= "{$this->environment} [".$this->external_id_format ."-". $order->id."]\n\n";
        
        $xendit_should_3ds = $_POST['xendit_should_3ds'];
        if ($xendit_should_3ds === 'true') {
            return $this->process_payment_must_3ds($order, $xendit_token);
        } else {
            return $this->process_payment_without_authenticate($order, $xendit_token);
        }
    }

    /**
     * Payment using must use 3ds flow.
     * 
     * @param WC_Order $order
     * @param string $xendit_token
     */
    private function process_payment_must_3ds($order, $xendit_token)
    {
        $log_msg = "process_payment_must_3ds(order, xendit_token) order: ".$order." xendit_token: ".$xendit_token."\n\n";
        $log_msg .= "{$this->environment} [".$this->external_id_format ."-". $order->id."]\n\n";

        $hosted_3ds_response = $this->create_hosted_3ds($order);

        $log_msg .= "create_hosted_3ds, hosted_3ds_response: ".print_r($hosted_3ds_response, true)."\n\n";
        
        if ('IN_REVIEW' === $hosted_3ds_response->status) {
            $log_msg .= "hosted_3ds_response->status === IN_REVIEW \n\n";
            
            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::INFO, true);
            
            return array(
                'result'   => 'success',
                'redirect' => esc_url_raw($hosted_3ds_response->redirect->url),
            );
        } else if ('VERIFIED' === $hosted_3ds_response->status) {
            $response = $this->xenditClass->request($this->generate_payment_request($order, $xendit_token, $hosted_3ds_response->authentication_id));

            if ($response->error_code === 'EXTERNAL_ID_ALREADY_USED_ERROR') {
                $response = $this->xenditClass->request($this->generate_payment_request($order, $xendit_token, $hosted_3ds_response->authentication_id, true));
                $log_msg .= "error_code === EXTERNAL_ID_ALREADY_USED_ERROR, response: ".$response." \n\n";
            }

            $log_msg .= "hosted_3ds_response->status === VERIFIED, response: ". print_r($response, true) ." \n\n";
            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::INFO, true);

            $this->process_response($response, $order);

            WC()->cart->empty_cart();

            do_action('wc_xendit_cc_process_payment', $response, $order);

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            );
        } else {
            $error_msg = 'Bank card issuer is not available or the connection is timed out, please try again with another card in a few minutes';

            $log_msg .= $error_msg." \n\n";

            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);

            throw new Exception($error_msg);
        }

        return $response;
    }

    /**
     * Payment without authenticate flow.
     * 
     * @param WC_Order $order
     * @param string $xendit_token
     */
    private function process_payment_without_authenticate($order, $xendit_token)
    {
        $log_msg = "process_payment_without_authenticate(order, xendit_token) order: ".$order." xendit_token: ".$xendit_token."\n\n";
        
        try {
            $log_msg .= "{$this->environment} [".$this->external_id_format ."-". $order->id."]\n\n";
        
            $response = $this->xenditClass->request($this->generate_payment_request($order, $xendit_token));

            if ($response->error_code === 'EXTERNAL_ID_ALREADY_USED_ERROR') {
                $response = $this->xenditClass->request($this->generate_payment_request($order, $xendit_token, null, true));
                $log_msg .= "error_code === EXTERNAL_ID_ALREADY_USED_ERROR, response: ".$response." \n\n";
            }

            $this->process_response($response, $order);

            WC()->cart->empty_cart();

            do_action('wc_xendit_cc_process_payment', $response, $order);

            // Return thank you page redirect.
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($order)
            );
        
        } catch (Exception $e) {
            $error_msg = $e->getMessage();

            $log_msg .= $error_msg." \n\n";

            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);

            throw $e;
        }
    }

    /**
     * Store extra meta data for an order from a Xendit Response.
     */
    public function process_response($response, $order)
    {
        if (is_wp_error($response)) {
            if ('source' === $response->get_error_code() && $source->token_id) {
                $token = WC_Payment_Tokens::get($source->token_id);
                $token->delete();
                $message = __('This card is no longer available and has been removed.', 'woocommerce-gateway-xendit');
                $order->add_order_note($message);

                WC_Xendit_PG_Logger::log('ERROR: Card removed error. ' . $message, WC_LogDNA_Level::ERROR, true);
                throw new Exception($message);
            }

            $localized_messages = $this->get_localized_messages();

            $message = isset($localized_messages[ $response->get_error_code() ]) ? $localized_messages[ $response->get_error_code() ] : $response->get_error_message();

            $order->add_order_note($message);

            WC_Xendit_PG_Logger::log('ERROR: Response error. ' . $message, WC_LogDNA_Level::ERROR, true);
            throw new Exception($message);
        }

        $error_code = isset($response->error_code) ? $response->error_code : null;

        if ($error_code !== null) {
            $message = 'Card charge error. Reason: ' . $this->failure_reason_insight($error_code);

            WC_Xendit_PG_Logger::log('ERROR: Error charge. Message: ' . $message, WC_LogDNA_Level::ERROR, true);
            throw new Exception($message);
        }

        if ($response->status !== 'CAPTURED') {
            $localized_messages = $this->get_localized_messages();

            $order->update_status('failed', sprintf(__('Xendit charges (Charge ID:'.$response->id.').', 'woocommerce-gateway-xendit'), $response->id));
            $message = $this->failure_reason_insight($response->failure_reason);
            $order->add_order_note($message);

            throw new Exception($message);
        }

        $order_id = version_compare(WC_VERSION, '3.0.0', '<') ? $order->id : $order->get_id();

        // Store other data such as fees
        if (isset($response->balance_transaction) && isset($response->balance_transaction->fee)) {
            // Fees and Net needs to both come from Xendit to be accurate as the returned
            // values are in the local currency of the Xendit account, not from WC.
            $fee = ! empty($response->balance_transaction->fee) ? WC_Xendit::format_number($response->balance_transaction, 'fee') : 0;
            $net = ! empty($response->balance_transaction->net) ? WC_Xendit::format_number($response->balance_transaction, 'net') : 0;
            update_post_meta($order_id, 'Xendit Fee', $fee);
            update_post_meta($order_id, 'Net Revenue From Xendit', $net);
        }

        $this->complete_cc_payment($order, $response->id, $response->status, $response->capture_amount);

        do_action('wc_gateway_xendit_process_response', $response, $order);

        return $response;
    }

    /* 
    * Get CC Setting
    */
    private function get_cc_settings() {
        global $wpdb;

        $cc_settings = get_transient('cc_settings_xendit_pg');

        if (empty($cc_settings)) {
            $cc_settings = $this->xenditClass->getCCSettings();
            set_transient('cc_settings_xendit_pg', $cc_settings, 60);
        }

        return $cc_settings;
    }
    
    /**
     * Save source to order.
     *
     * @param WC_Order $order For to which the source applies.
     * @param stdClass $source Source information.
     */
    protected function save_source($order, $source)
    {
        WC_Xendit_PG_Logger::log('WC_Xendit_CC::save_source called in Xendit with order ==> ' . print_r($order, true) . 'and source ==> ' . print_r($source, true));

        $order_id = version_compare(WC_VERSION, '3.0.0', '<') ? $order->id : $order->get_id();

        // Store source in the order.
        if ($source->source) {
            version_compare(WC_VERSION, '3.0.0', '<') ? update_post_meta($order_id, '_xendit_card_id', $source->source) : $order->update_meta_data('_xendit_card_id', $source->source);
        }

        if (is_callable(array( $order, 'save' ))) {
            $order->save();
        }
    }

    /**
     * Refund a charge
     * @param  int $order_id
     * @param  float $amount
     * @return bool
     */
    public function process_refund($order_id, $amount = null, $reason = '', $duplicated = false)
    {
        $order = wc_get_order($order_id);

        if (! $order || ! $order->get_transaction_id()) {
            return false;
        }

        $default_external_id = $this->external_id_format . '-' . $order->get_transaction_id();
        $body = array(
            'store_name'    => get_option('blogname'),
            'external_id'   => $duplicated ? $default_external_id . '-' . uniqid() : $default_external_id
        );

        if (is_null($amount)) {
            return false;
        }

        if ((float)$amount < 1) {
            return false;
        }

        if (! is_null($amount)) {
            $body['amount']	= $amount;
        }

        if ($reason) {
            $body['metadata'] = array(
                'reason'	=> $reason,
            );
        }

        WC_Xendit_PG_Logger::log("Info: Beginning refund for order $order_id for the amount of {$amount}");

        $response = $this->xenditClass->request($body, 'charges/' . $order->get_transaction_id() . '/refund');

        if (is_wp_error($response)) {
            WC_Xendit_PG_Logger::log('Error: ' . $response->get_error_message(), WC_LogDNA_Level::ERROR, true);
            return false;
        } elseif (! empty($response->id)) {
            $refund_message = sprintf(__('Refunded %1$s - Refund ID: %2$s - Reason: %3$s', 'woocommerce-gateway-xendit'), wc_price($response->amount), $response->id, $reason);
            $order->add_order_note($refund_message);
            WC_Xendit_PG_Logger::log('Success: ' . html_entity_decode(strip_tags($refund_message)));
            return true;
        } elseif (! empty($response->error_code)) {
            if ($response->error_code === 'DUPLICATE_REFUND_ERROR') {
                return $this->process_refund($order_id, $amount, $reason, true);
            }

            WC_Xendit_PG_Logger::log('Error: ' . $response->message, WC_LogDNA_Level::ERROR, true);
            return false;
        }
    }

    /**
     * Sends the failed order email to admin
     *
     * @version 3.1.0
     * @since 3.1.0
     * @param int $order_id
     * @return null
     */
    public function send_failed_order_email($order_id)
    {
        $emails = WC()->mailer()->get_emails();
        if (! empty($emails) && ! empty($order_id)) {
            $emails['WC_Email_Failed_Order']->trigger($order_id);
        }
    }

    public function create_hosted_3ds($order)
    {
        $log_msg = "create_hosted_3ds start\n\n";
        
        $additional_data = WC_Xendit_PG_Helper::generate_items_and_customer($order);
        
        $log_msg = "generate_items_and_customer, result: ".print_r($additional_data, true)." \n\n";

        $args = array(
            'utm_nooverride' => '1',
            'order_id'       => $order->get_id(),
        );
        $return_url = esc_url_raw(add_query_arg($args, $this->get_return_url($order)));
        
        $hosted_3ds_data = array(
            'token_id'		        => wc_clean($_POST['xendit_token'] ? $_POST['xendit_token'] : $xendit_token->source),
            'amount'		        => $order->get_total(),
            'external_id'	        => $this->external_id_format .'-'. $order->get_id(),
            'platform_callback_url' => $this->xendit_callback_url,
            'return_url'	        => $return_url, //thank you page
            'failed_return_url'     => wc_get_checkout_url(),
            'items'                 => isset($additional_data['items']) ? $additional_data['items'] : '',
            'customer'              => $this->get_customer_details($order),
        );

        $hosted_3ds_response = $this->xenditClass->request($hosted_3ds_data, 'hosted-3ds', 'POST', array(
            'should_use_public_key'	=> true
        ));
        
        $log_msg = "Hosted 3ds response, result: ".print_r($hosted_3ds_response, true)." \n\n";
        
        if (! empty($hosted_3ds_response->error)) {
            $localized_message = $hosted_3ds_response->error->message;

            $order->add_order_note($localized_message);

            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);

            throw new WP_Error(print_r($hosted_3ds_response, true), $localized_message);
        }

        if (WC_Xendit_PG_Helper::is_wc_lt('3.0')) {
            update_post_meta($order_id, '_xendit_hosted_3ds_id', $hosted_3ds_response->id);
        } else {
            $order->update_meta_data('_xendit_hosted_3ds_id', $hosted_3ds_response->id);
            $order->save();
        }

        WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::INFO, true);
        
        return $hosted_3ds_response;
    }

    public function is_valid_for_use()
    {
        return in_array(get_woocommerce_currency(), apply_filters(
            'woocommerce_' . $this->id . '_supported_currencies',
            $this->supported_currencies
        ));
    }

    public function xendit_status_payment_gateways($gateways)
    {
        global $wpdb, $woocommerce;
        
        if (is_null($woocommerce->cart)) {
            return $gateways;
        }

        if ($this->enabled == 'no') {
            unset($gateways[$this->id]);
            return $gateways;
        }

        if ($this->secret_key == ""):
            unset($gateways[$this->id]);

        return $gateways;
        endif;

        if (!$this->is_valid_for_use()) {
            unset($gateways[$this->id]);

            return $gateways;
        }

        return $gateways;
    }

    /**
     * Map card's failure reason to more detailed explanation based on current insight.
     *
     * @param $failure_reason
     * @return string
     */
    private function failure_reason_insight($failure_reason)
    {
        switch ($failure_reason) {
            case 'CARD_DECLINED':
            case 'STOLEN_CARD': return 'CARD_DECLINED - The bank that issued this card declined the payment but didn\'t tell us why.
                Try another card, or try calling your bank to ask why the card was declined.';
            case 'INSUFFICIENT_BALANCE': return $failure_reason . ' - Your bank declined this payment due to insufficient balance. Ensure
                that sufficient balance is available, or try another card';
            case 'INVALID_CVN': return $failure_reason . ' - Your bank declined the payment due to incorrect card details entered. Try to
                enter your card details again, including expiration date and CVV';
            case 'INACTIVE_CARD': return $failure_reason . ' - This card number does not seem to be enabled for eCommerce payments. Try
                another card that is enabled for eCommerce, or ask your bank to enable eCommerce payments for your card.';
            case 'EXPIRED_CARD': return $failure_reason . ' - Your bank declined the payment due to the card being expired. Please try
                another card that has not expired.';
            case 'PROCESSOR_ERROR': return 'We encountered issue in processing your card. Please try again with another card';
            case 'AUTHENTICATION_FAILED': return 'Authentication process failed. Please try again';
            default: return $failure_reason;
        }
    }

    private function cancel_order($order, $note)
    {
        $order->update_status('wc-cancelled');
        $order->add_order_note($note);
    }

    /**
     * Retrieve customer details. Currently will intro this new structure
     * on cards endpoint only.
     * 
     * Source: https://docs.woocommerce.com/wc-apidocs/class-WC_Order.html
     * 
     * @param $order
     */
    private function get_customer_details($order)
    {
        $customer_details = array();

        $billing_details = array();
        $billing_details['first_name'] = $order->get_billing_first_name();
        $billing_details['last_name'] = $order->get_billing_last_name();
        $billing_details['email'] = $order->get_billing_email();
        $billing_details['phone_number'] = $order->get_billing_phone();
        $billing_details['address_city'] = $order->get_billing_city();
        $billing_details['address_postal_code'] = $order->get_billing_postcode();
        $billing_details['address_line_1'] = $order->get_billing_address_1();
        $billing_details['address_line_2'] = $order->get_billing_address_2();
        $billing_details['address_state'] = $order->get_billing_state();
        $billing_details['address_country'] = $order->get_billing_country();


        $shipping_details = array();
        $shipping_details['first_name'] = $order->get_shipping_first_name();
        $shipping_details['last_name'] = $order->get_shipping_last_name();
        $shipping_details['address_city'] = $order->get_shipping_city();
        $shipping_details['address_postal_code'] = $order->get_shipping_postcode();
        $shipping_details['address_line_1'] = $order->get_shipping_address_1();
        $shipping_details['address_line_2'] = $order->get_shipping_address_2();
        $shipping_details['address_state'] = $order->get_shipping_state();
        $shipping_details['address_country'] = $order->get_shipping_country();

        $customer_details['billing_details'] = $billing_details;
        $customer_details['shipping_details'] = $shipping_details;

        return json_encode($customer_details);
    }

    public function complete_cc_payment($order, $charge_id, $status, $amount)
    {
        global $woocommerce;

        $order_id = version_compare(WC_VERSION, '3.0.0', '<') ? $order->id : $order->get_id();

        if (!$order->is_paid()) {
            $notes = json_encode(
                array(
                    'charge_id' => $charge_id,
                    'status' => 'CAPTURED',
                    'paid_amount' => $amount,
                )
            );
    
            $note = "Xendit Payment Response:" . "{$notes}";
    
            $order->add_order_note('Xendit payment successful');
            $order->add_order_note($note);
            $order->payment_complete($charge_id);
    
            update_post_meta($order_id, '_xendit_charge_id', $charge_id);
            update_post_meta($order_id, '_xendit_charge_captured', 'yes');
            $message = sprintf(__('Xendit charge complete (Charge ID: %s)', 'woocommerce-gateway-xendit'), $charge_id);
            $order->add_order_note($message);
    
            // Reduce stock levels
            $order->reduce_order_stock();
        }
    }

    public function validate_payment($response)
    {
        global $wpdb, $woocommerce;

        $order_id = $response->external_id;

        $xendit_status = $this->xendit_status;
        $log_msg = "WC_Xendit_CC::validate_payment() [" . $response->external_id . " {$xendit_status}\n\n";

        if ($order_id) {
            $exploded_ext_id = explode("-", $order_id);
            $order_num = end($exploded_ext_id);

            if (!is_numeric($order_num)) {
                $exploded_ext_id = explode("_", $order_id);
                $order_num = end($exploded_ext_id);
            }

            sleep(3);
            $is_changing_status = $this->get_is_changing_order_status($order_num);

            if ($is_changing_status) {
                echo 'Already changed with redirect';
                exit;
            }

            $order = new WC_Order($order_num);

            if ($this->developmentmode != 'yes') {
                $payment_gateway = wc_get_payment_gateway_by_order($order->id);
                if (false === get_post_status($order->id) || strpos($payment_gateway->id, 'xendit')) {
                    WC_Xendit_PG_Logger::log($log_msg . "Xendit is live and require a valid order id!", WC_LogDNA_Level::ERROR, true);

                    header('HTTP/1.1 400 Invalid Data Received');
                    exit;
                }
            }

            $charge = $this->xenditClass->getCharge($response->id);

            if (isset($charge['error_code'])) {
                WC_Xendit_PG_Logger::log(
                    $log_msg . "Callback error in getting credit card charge. Error code: " . $charge['error_code'],
                    WC_LogDNA_Level::ERROR,
                    true
                );
                header('HTTP/1.1 400 Invalid Credit Card Charge Data Received');
                exit;
            }

            if ('CAPTURED' == $charge['status']) {
                WC_Xendit_PG_Logger::log($log_msg . "Credit card charge is " . $charge['status'] .", proccess order!", WC_LogDNA_Level::INFO, true);

                $mailer = $woocommerce->mailer();
                $admin_email = get_option('admin_email');

                $message = $mailer->wrap_message(__('Order confirmed', 'xendit'), sprintf(__('Order %s has been confirmed', 'xendit'), $order->get_order_number()));
                if (false === $order->id) {
                    $mailer->send($admin_email, sprintf(__('Payment for order %s confirmed', 'xendit'), $order->get_order_number()), $message);
                }

                $message = $mailer->wrap_message(__('Order confirmed', 'xendit'), sprintf(__('Order %s has been confirmed', 'xendit'), $order->get_order_number()));
                if (false === $order->id) {
                    $mailer->send($order->billing_email, sprintf(__('Payment for order %s confirmed', 'xendit'), $order->get_order_number()), $message);
                }

                $this->complete_cc_payment($order, $charge['id'], $charge['status'], $charge['capture_amount']);

                $this->xenditClass->trackEvent(array(
                    'reference' => 'charge_id',
                    'reference_id' => $charge['id'],
                    'event' => 'ORDER_UPDATED_AT.CALLBACK'
                ));

                WC_Xendit_PG_Logger::log($log_msg . "Order #{$order->id} now marked as complete with Xendit!", WC_LogDNA_Level::INFO, true);

                //die(json_encode($response, JSON_PRETTY_PRINT)."\n");
                die('SUCCESS');
            } else {
                WC_Xendit_PG_Logger::log($log_msg . "Credit card charge status is " . $charge['status'] . ", proccess order declined!", WC_LogDNA_Level::ERROR, true);

                $notes = json_encode(
                    array(
                        'charge_id' => $charge['id'],
                        'status' => $charge['status'],
                        'paid_amount' => $charge['capture_amount'],
                    )
                );

                $order->update_status('failed', sprintf(__('Xendit charges (Charge ID: %s).', 'woocommerce-gateway-xendit'), $charge['id']));
                $message = $this->failure_reason_insight($charge['failure_reason']);
                $order->add_order_note($message);

                $note = "Xendit Payment Response:" . "{$notes}";
                $order->add_order_note($note);

                header('HTTP/1.1 400 Invalid Data Received');
                exit;
            }
        } else {
            WC_Xendit_PG_Logger::log($log_msg . "Xendit external id check not passed, break!", WC_LogDNA_Level::ERROR, true);

            header('HTTP/1.1 400 Invalid Data Received');
            exit;
        }
    }
    
    /** 
     * Show error base on query
    */
    function show_checkout_error(  ) { 
        if(isset($_REQUEST['error'])) {
            $notices = wc_add_notice(  $this->failure_reason_insight($_REQUEST['error']), 'error' );
            wp_safe_redirect(wc_get_checkout_url());
        }
    }

    public function update_status_complete($order_id)
    {
        global $wpdb, $woocommerce;

        $order = new WC_Order($order_id);

        return $this->success_payment_xendit;
    }

    function update_order_status($order_id) {
        if (!$order_id){
            return;
        }

        $order = new WC_Order($order_id);
        if ($order->status == 'processing' && $order->status != $this->success_payment_xendit) {
            $order->update_status($this->success_payment_xendit);
        }
        return;
    }

    public function get_is_changing_order_status($order_id, $state = true)
    {
        $transient_key = 'xendit_is_changing_order_status_' . $order_id;

        $is_changing_order_status = get_transient($transient_key);

        if (empty($is_changing_order_status)) {
            set_transient($transient_key, $state, 60);

            return false;
        }
        
        return $is_changing_order_status;
    }
}
