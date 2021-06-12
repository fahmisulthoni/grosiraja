<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Xendit_Invoice extends WC_Payment_Gateway
{
    const DEFAULT_MINIMUM_AMOUNT = 10000;
    const DEFAULT_MAXIMUM_AMOUNT = 1000000000;
    const DEFAULT_EXTERNAL_ID_VALUE = 'woocommerce-xendit';
    const DEFAULT_CHECKOUT_FLOW = 'CHECKOUT_PAGE';

    public function __construct()
    {
        global $woocommerce;

        $this->id = 'xendit_gateway';
        $this->has_fields = true;
        $this->method_title = 'Xendit';
        $this->method_description = sprintf(__('Collect payment from Bank Transfer (Virtual Account) on checkout page and get the report realtime on your Xendit Dashboard. <a href="%1$s" target="_blank">Sign In</a> or <a href="%2$s" target="_blank">sign up</a> on Xendit and integrate with <a href="%3$s" target="_blank">your Xendit keys</a>.', 'woocommerce-gateway-xendit'), 'https://dashboard.xendit.co/auth/login', 'https://dashboard.xendit.co/register', 'https://dashboard.xendit.co/settings/developers#api-keys');
        $this->method_code = $this->method_title;
        $this->supported_currencies = array(
            'IDR'
        );

        $this->init_form_fields();
        $this->init_settings();

        // user setting variables
        $this->title = 'Payment Gateway';
        $this->description = 'Pay with Xendit';

        $this->developmentmode = $this->get_option('developmentmode');
        $this->showlogo = 'yes';

        $this->success_response_xendit = 'COMPLETED';
        $this->success_payment_xendit = $this->get_option('success_payment_xendit');
        $this->responce_url_sucess = $this->get_option('responce_url_calback');
        $this->checkout_msg = 'Thank you for your order, please follow the account numbers provided to pay with secured Xendit.';
        $this->xendit_callback_url = home_url() . '/?xendit_mode=xendit_invoice_callback';

        $this->xendit_status = $this->developmentmode == 'yes' ? "[Development]" : "[Production]";

        $this->msg['message'] = "";
        $this->msg['class'] = "";

        $this->amount_to_live = $this->get_option('amount_to_live');
        $this->time_to_live = $this->get_option('time_to_live');
        $this->external_id_format = !empty($this->get_option('external_id_format')) ? $this->get_option('external_id_format') : self::DEFAULT_EXTERNAL_ID_VALUE;
        $this->redirect_after = !empty($this->get_option('redirect_after')) ? $this->get_option('redirect_after') : self::DEFAULT_CHECKOUT_FLOW;

        $this->api_server_live = 'https://api.xendit.co';
        $this->api_server_test = 'https://api.xendit.co';

        $this->merchant_name = '';
        $this->publishable_key = $this->developmentmode == 'yes' ? $this->get_option('api_key_dev') : $this->get_option('api_key');
        $this->secret_key = $this->developmentmode == 'yes' ? $this->get_option('secret_key_dev') : $this->get_option('secret_key');

        $options['secret_api_key'] = $this->secret_key;
        $options['public_api_key'] = $this->publishable_key;
        $options['server_domain'] = $this->get_server_url();

        $this->xenditClass = new WC_Xendit_PG_API($options);

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array(&$this, 'receipt_page'));
        add_action('woocommerce_order_details_after_order_table', array(&$this, 'xendit_checkout_details'));
        add_action('wp_enqueue_scripts', array(&$this, 'load_xendit_script'));

        add_action('admin_notices', array($this, 'show_admin_notice_warning_on_test_mode'));
        add_action('admin_notices', array($this, 'fail_expired_invoice_order'));

        add_filter('woocommerce_available_payment_gateways', array(&$this, 'xendit_status_payment_gateways'));
        add_filter('woocommerce_payment_complete_order_status', array(&$this, 'update_status_complete'));

        wp_register_script('sweetalert', 'https://unpkg.com/sweetalert@2.1.2/dist/sweetalert.min.js', null, null, true);
        wp_enqueue_script('sweetalert');
    }

    public function fail_expired_invoice_order()
    {
        $customer_orders = wc_get_orders(array(
            'status' => array('wc-pending'),
        ));
        $bulk_cancel_data = array();

        foreach ($customer_orders as $order) {
            $payment_method = $order->get_payment_method();
            $invoice_exp = get_post_meta($order->get_id(), 'Xendit_expiry', true);
            $invoice_id = get_post_meta($order->get_id(), 'Xendit_invoice', true);

            if (
                preg_match('/xendit/i', $payment_method) &&
                metadata_exists('post', $order->get_id(), 'Xendit_expiry') &&
                $invoice_exp < time()
            ) {
                $order->update_status('wc-cancelled');

                $bulk_cancel_data[] = array(
                    'id' => $invoice_id,
                    'expiry_date' => $invoice_exp,
                    'order_number' => strval($order->get_id()),
                    'amount' => $order->get_total()
                );
            }
        }

        if (!empty($bulk_cancel_data)) {
            return $this->xenditClass->trackOrderCancellation($bulk_cancel_data);
        }
    }

    public function show_admin_notice_warning_on_test_mode()
    {
        $class = 'notice notice-warning';
        $message = __('Xendit Virtual Accounts Plugin is in TEST Mode. Configure to receive real payments.', 'xendit');

        if ($this->developmentmode == 'yes' && $this->id == 'xendit_gateway') {
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
        }
    }

    public function is_valid_for_use()
    {
        return in_array(get_woocommerce_currency(), apply_filters(
            'woocommerce_' . $this->id . '_supported_currencies',
            $this->supported_currencies
        ));
    }

    public function load_xendit_script()
    {
        wp_enqueue_script('xendit-gateway', plugins_url('assets/xendit.app.js', WC_XENDIT_PG_MAIN_FILE), array('wc-checkout'), false, true);
    }

    public function admin_options()
    {
        if (!$this->is_valid_for_use()) {
            echo '<div class="inline error">
                <p>
                    <strong>Gateway Disabled. <strong>'
                . $this->method_title . ' does not support your currency.
                </p>
            </div>';
            return;
        } ?>
        <h3><?php _e('Xendit Payment Gateway Options', 'woocommerce'); ?>
        </h3>
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>

        <style>
            .xendit-ttl-wrapper {
                width: 400px;
                position: relative;
            }

            .xendit-ttl,
            .xendit-ext-id {
                width: 320px !important;
            }

            .xendit-form-suffix {
                width: 70px;
                position: absolute;
                bottom: 6px;
                right: 0;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                <?php if ($this->developmentmode == 'yes') { ?>
                    $('.xendit_dev').parents('tr').show();
                    $('.xendit_live').parents('tr').hide();
                <?php } else { ?>
                    $('.xendit_dev').parents('tr').hide();
                    $('.xendit_live').parents('tr').show();
                <?php } ?>

                $(".xendit-ttl").wrap("<div class='xendit-ttl-wrapper'></div>");
                $("<span class='xendit-form-suffix'>Seconds</span>").insertAfter(".xendit-ttl");

                $(".xendit-ext-id").wrap("<div class='input-text regular-input xendit-ttl-wrapper'></div>");
                $("<span class='xendit-form-suffix'>-order_id</span>").insertAfter(".xendit-ext-id");

                $("#ext-id-format").text(
                    "<?= $this->external_id_format ?>-order_id");
                $("#ext-id-example").text(
                    "<?= $this->external_id_format ?>-4245");

                $("#woocommerce_<?= $this->id; ?>_external_id_format").change(
                    function() {
                        $("#ext-id-format").text($(this).val() + "-orderID");
                        $("#ext-id-example").text($(this).val() + "-4245");
                    });

                var isSubmitCheckDone = false;

                $("button[name='save']").on('click', function(e) {
                    if (isSubmitCheckDone) {
                        isSubmitCheckDone = false;
                        return;
                    }

                    e.preventDefault();
                    var newValue = {
                        api_key: $(
                            "#woocommerce_<?= $this->id; ?>_api_key"
                        ).val(),
                        secret_key: $(
                            "#woocommerce_<?= $this->id; ?>_secret_key"
                        ).val(),
                        api_key_dev: $(
                            "#woocommerce_<?= $this->id; ?>_api_key_dev"
                        ).val(),
                        secret_key_dev: $(
                            "#woocommerce_<?= $this->id; ?>_secret_key_dev"
                        ).val()
                    };
                    var oldValue = {
                        api_key: '<?= $this->get_option('api_key'); ?>',
                        secret_key: '<?= $this->get_option('secret_key'); ?>',
                        api_key_dev: '<?= $this->get_option('api_key_dev'); ?>',
                        secret_key_dev: '<?= $this->get_option('secret_key_dev'); ?>'
                    };

                    if (!_.isEqual(newValue, oldValue)) {
                        return swal({
                            title: 'Are you sure?',
                            text: 'Changing your API key will affect your transaction.',
                            buttons: {
                                confirm: {
                                    text: 'Change my API key',
                                    value: true
                                },
                                cancel: 'Cancel'
                            }
                        }).then(function(value) {
                            if (value) {
                                isSubmitCheckDone = true;
                                $("button[name='save']").trigger('click');
                            } else {
                                e.preventDefault();
                            }
                        });
                    }

                    var externalIdValue = $(
                        "#woocommerce_<?= $this->id; ?>_external_id_format"
                    ).val();
                    if (externalIdValue.length === 0) {
                        return swal({
                            type: 'error',
                            title: 'Invalid External ID Format',
                            text: 'External ID cannot be empty, please input one or change it to woocommerce-xendit'
                        }).then(function() {
                            e.preventDefault();
                        });
                    }

                    if (/[^a-z0-9-]/gmi.test(externalIdValue)) {
                        return swal({
                            type: 'error',
                            title: 'Unsupported Character',
                            text: 'The only supported characters in external ID are alphanumeric (a - z, 0 - 9) and -, Please change any symbol or other'
                        }).then(function() {
                            e.preventDefault();
                        });
                    }

                    if (externalIdValue.length <= 5 || externalIdValue.length > 54) {
                        return swal({
                            type: 'error',
                            title: 'External ID are too long',
                            text: 'The maximum length that is supported by our external ID is 54 characters, Please reduce the length of your external ID'
                        }).then(function() {
                            e.preventDefault();
                        });
                    }

                    isSubmitCheckDone = true;
                    $("button[name='save']").trigger('click');
                });

                $("#woocommerce_<?= $this->id; ?>_developmentmode").change(
                    function() {
                        if (this.checked) {
                            $(".xendit_dev").parents("tr").show();
                            $(".xendit_live").parents("tr").hide();
                        } else {
                            $(".xendit_dev").parents("tr").hide();
                            $(".xendit_live").parents("tr").show();
                        }
                    });
            });
        </script>
<?php
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable :', 'xendit'),
                'type' => 'checkbox',
                'label' => __('Enable Xendit Gateway.', 'xendit'),
                'default' => 'no',
            ),

            'developmentmode' => array(
                'title' => __('Test Environment :', 'xendit'),
                'type' => 'checkbox',
                'label' => __('Enable Test Environment - Please uncheck for processing real transaction', 'xendit'),
                'default' => 'no',
            ),

            'time_to_live' => array(
                'title' => __('Expiry Time :', 'xendit'),
                'class' => 'xendit-ttl',
                'type' => 'number',
                'description' => __('If end customer do not pay until the time above since they place the order with bank transfer or retail outlet, the order will be cancelled automatically', 'xendit'),
                'default' => __('', 'xendit')
            ),

            'amount_to_live' => array(
                'title' => __('Min. Amount (IDR) :', 'xendit'),
                'type' => 'number',
                'description' => __('Hide payment option if cart is less than this amount', 'xendit'),
                'default' => __('10000', 'xendit'),
            ),

            'success_payment_xendit' => array(
                'title' => __('Successful Payment Status :', 'xendit'),
                'type' => 'select',
                'description' => __('The status that WooCommerce should show when a payment is successful.', 'xendit'),
                'default' => 'processing',
                'class' => 'form-control',
                'options' => array(
                    'pending' => __('Pending payment', 'xendit'),
                    'processing' => __('Processing', 'xendit'),
                    'completed' => __('Completed', 'xendit'),
                    'on-hold' => __('On Hold', 'xendit'),
                ),
            ),

            'external_id_format' => array(
                'title' => __('External ID Format :', 'xendit'),
                'class' => 'xendit-ext-id',
                'type' => 'text',
                'description' => __('External ID of the payment that will be created on Xendit. It will show <strong><span id="ext-id-format"></span></strong>, for example <span id="ext-id-example"></span>', 'xendit'),
                'default' => __(self::DEFAULT_EXTERNAL_ID_VALUE, 'xendit'),
            ),

            'redirect_after' => array(
                'title' => __('Redirect Invoice After :', 'xendit'),
                'type' => 'select',
                'description' => __('We will show the XenInvoice page after selected option. Choose Order Received page to get better tracking of your order conversion if you are using analytic platform.', 'xendit'),
                'default' => 'CHECKOUT_PAGE',
                'class' => 'form-control',
                'options' => array(
                    'CHECKOUT_PAGE' => __('Checkout page', 'xendit'),
                    'ORDER_RECEIVED_PAGE' => __('Order received page', 'xendit'),
                ),
            ),

            'api_key' => array(
                'style' => '',
                'class' => 'xendit_live',
                'title' => __('Xendit Public API Key :', 'xendit'),
                'type' => 'password',
                'description' => __('Unique Live API key given by xendit. <strong>Case Sensitive!</strong>', 'xendit'),
                'default' => __('', 'xendit'),
            ),

            'secret_key' => array(
                'style' => '',
                'class' => 'xendit_live',
                'title' => __('Xendit Secret API Key :', 'xendit'),
                'type' => 'password',
                'description' => __('Unique Live Secret key given by xendit. <strong>Case Sensitive!</strong>', 'xendit'),
                'default' => __('', 'xendit'),
            ),

            'api_key_dev' => array(
                'style' => '',
                'class' => 'xendit_dev',
                'title' => __('Xendit Public API Key [DEV] :', 'xendit'),
                'type' => 'password',
                'description' => __('Unique Development API key given by xendit. <strong>Case Sensitive!</strong>', 'xendit'),
                'default' => __('', 'xendit'),
            ),

            'secret_key_dev' => array(
                'style' => '',
                'class' => 'xendit_dev',
                'title' => __('Xendit Secret API Key [DEV] :', 'xendit'),
                'type' => 'password',
                'description' => __('Unique Development Secret key given by xendit. <strong>Case Sensitive!</strong>', 'xendit'),
                'default' => __('', 'xendit'),
            ),

        );
    }

    public function get_server_url()
    {
        if ($this->developmentmode == 'yes') {
            return $this->api_server_test;
        } else {
            return $this->api_server_live;
        }
    }

    public function payment_fields()
    {
        global $woocommerce;
        if (!empty($this->description)) {
            if ($this->id !== 'xendit_gateway') {
                $test_description = '';
                if ($this->developmentmode == 'yes') {
                    $test_description = sprintf(__('<strong>TEST MODE.</strong> Bank account number below are for testing. Real payment will not be detected.', 'woocommerce-gateway-xendit'));
                }
                echo '<p style="margin-bottom:0;">' . $this->description . '</p>
                <p style="color: red; font-size:80%; margin-top:0;">' . $test_description . '</p>';

                return;
            }

            if ($this->developmentmode == 'yes') {
                $test_description = sprintf(__('<strong>TEST MODE.</strong> The bank account numbers shown on next page are for testing only.', 'woocommerce-gateway-xendit'));
                $this->description = trim($test_description . '<br />' . $this->description);
            }
            echo wpautop(wptexturize($this->description));
        }
    }

    public function receipt_page($order_id)
    {
        global $wpdb, $woocommerce;

        $order = new WC_Order($order_id);
        $curr_symbole = get_woocommerce_currency();

        $payment_gateway = wc_get_payment_gateway_by_order($order->id);
        if ($payment_gateway->id != $this->id) {
            return;
        }

        $invoice = get_post_meta($order->id, 'Xendit_invoice', true);
        $invoice_exp = get_post_meta($order->id, 'Xendit_expiry', true);

        $data = $this->xenditClass->getInvoice($invoice);

        $return = "";
        $return .= '<div style="text-align:left;"><strong>' . $this->checkout_msg . '</strong><br /><br /></div>';

        if ($this->developmentmode == 'yes') {
            $testDescription = sprintf(__('<strong>TEST MODE.</strong> The bank account numbers shown below are for testing only. Real payments will not be detected.', 'woocommerce-gateway-xendit'));
            $return .= '<div style="text-align:left;">' . $testDescription . '</div>';
        }

        echo $return;
    }

    public function process_payment($order_id)
    {
        global $wpdb, $woocommerce;
        $order = new WC_Order($order_id);
        $amount = $order->order_total;

        $log_msg = "WC_Xendit_Invoice::process_payment($order_id) [".$this->external_id_format . '-' . $order_id."] {$this->xendit_status}\n\n";

        if ($amount < WC_Xendit_Invoice::DEFAULT_MINIMUM_AMOUNT) {
            $this->cancel_order($order, 'Cancelled because amount is below minimum amount');
            $log_msg .= "Cancelled because amount is below minimum amount. Amount = $amount\n\n";
            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);

            throw new Exception(sprintf(__(
                'The minimum amount for using this payment is %1$s. Please put more item to reach the minimum amount. <br />' .
                    '<a href="%2$s">Your Cart</a>',
                'woocommerce-gateway-xendit'
            ), wc_price(WC_Xendit_Invoice::DEFAULT_MINIMUM_AMOUNT), wc_get_cart_url()));
        }

        if ($amount > WC_Xendit_Invoice::DEFAULT_MAXIMUM_AMOUNT) {
            $this->cancel_order($order, 'Cancelled because amount is above maximum amount');
            $log_msg .= "Cancelled because amount is above maximum amount. Amount = $amount\n\n";
            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);

            throw new Exception(sprintf(__(
                'The maximum amount for using this payment is %1$s. Please remove one or more item(s) from your cart. <br />' .
                    '<a href="%2$s">Your Cart</a>',
                'woocommerce-gateway-xendit'
            ), wc_price(WC_Xendit_Invoice::DEFAULT_MAXIMUM_AMOUNT), wc_get_cart_url()));
        }

        $this->confirmation_email($order_id);

        switch ($this->redirect_after) {
            case 'ORDER_RECEIVED_PAGE':
                $args = array(
                    'utm_nooverride' => '1',
                    'order_id'       => $order->get_id(),
                );
                $return_url = esc_url_raw(add_query_arg($args, $this->get_return_url($order)));
                break;
            case 'CHECKOUT_PAGE':
            default:
                $return_url = get_post_meta($order->id, 'Xendit_invoice_url', true);
        }

        // Set payment pending
        $order->update_status('pending', __('Awaiting Xendit payment', 'xendit'));

        // Return thankyou redirect
        return array(
            'result' => 'success',
            'redirect' => $return_url,
        );
    }

    private function render_payment_details($xendit_invoice, $order)
    {
        if ($xendit_invoice['status'] == 'PAID' || $xendit_invoice['status'] == 'COMPLETED') {
            $billing_name = implode(" ", array($order->billing_first_name, $order->billing_last_name));
            return $testMessage . '
                <p><strong>' . sprintf(__('Hello %s, Your payment is complete.<br />Thank you for your shopping!', 'xendit'), $billing_name) . '</strong></p>
                <p><a class="button paid" href="' . $order->get_view_order_url() . '">' . __('Check order now!', 'xendit') . '<a></p>
                ';
        }

        if ($order->status == 'pending' || $order->status == 'on-hold') :
            if (isset($xendit_invoice['error_code'])) {
                return;
            }

            $bank_order = array('MANDIRI', 'BRI', 'PERMATA', 'BCA', 'BNI');
            $banks = array();
            foreach ($bank_order as $current) {
                foreach ($xendit_invoice['available_banks'] as $available_banks) {
                    if ($current == $available_banks['bank_code']) {
                        $banks[] = $available_banks;
                    }
                }
            }

            $open_banks = count($banks);
            $colspan = $open_banks;

            echo $testMessage;
            echo '<h2>' . __('Payment data', 'xendit') . '</h2>';
            echo '
                <table class="shop_table order_details">
                    <tbody>
                        <tr>
                            <td colspan="' . $colspan . '">
                            <div style="text-align:left;">
                                <strong>' . sprintf(__('You can also pay your invoice to %s this Bank Account:', 'xendit'), $open_banks > 1 ? 'one of' : '') . '</strong>
                            </div>
                            </td>
                        </tr>';
            echo '<tr>';

            foreach ($banks as $bank) {
                if ($bank['bank_code'] == 'MANDIRI') {
                    echo '<td><span style="color:blue">From Mandiri Account Only</span></td>';
                }
                if ($bank['bank_code'] == 'BRI') {
                    echo '<td><span style="color:blue">From BRI Account Only</span></td>';
                }
                if ($bank['bank_code'] == 'PERMATA') {
                    echo '<td><span style="color:blue">From Permata Account Only</span></td>';
                }
                if ($bank['bank_code'] == 'BCA') {
                    echo '<td><span style="color:blue">From BCA Account Only</span></td>';
                }
                if ($bank['bank_code'] == 'BNI') {
                    echo '<td><span style="color:blue">From any bank account</span></td>';
                }
            }
            echo '</tr>';
            echo '<tr>';
            $i = 0;
            foreach ($banks as $bank) {
                echo '
                    <td width="33%", style="vertical-align: top;">
                        <div style="text-align:left; padding:10px;">
                            <img src="' . plugins_url('assets/images/' . strtolower($bank['bank_code']) . '.png', WC_XENDIT_PG_MAIN_FILE) . '" style="max-width:180px;width:100%;" class="img-responsive">
                        </div>
                        <div style="text-align:left; padding:10px;">
                            ' . sprintf(__('Bank Name: <strong>%s</strong>', 'xendit'), $bank['bank_code']) . '<br />
                            ' . sprintf(__('Account Number: <strong>%s</strong>', 'xendit'), $bank['bank_account_number']) . '<br />
                            ' . sprintf(__('Account Holder: <strong>%s</strong>', 'xendit'), $bank['account_holder_name']) . '<br />
                            ' . sprintf(__('Bank Branch: <strong>%s</strong>', 'xendit'), $bank['bank_branch']) . '<br />
                            ' . sprintf(__('Unique Amount: <strong>%s</strong>', 'xendit'), wc_price($bank['transfer_amount'])) . '<br />
                        </div>
                    </td>
                ';
                $i++;
            }
            echo '</tr>';

            echo '
                    <tr>
                        <td colspan="' . $colspan . '">
                            <div style="text-align:left;">
                                <strong><a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel order &amp; restore cart', 'xendit') . '</a></strong>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            ';
        endif;
    }

    public function xendit_checkout_details($getOrder)
    {
        global $wpdb;

        $order = new WC_Order($getOrder);

        $order_id = WC_Xendit_PG_Helper::is_wc_lt('3.0') ? $order->id : $order->get_id();

        $payment_gateway = wc_get_payment_gateway_by_order($order_id);
        if ($payment_gateway->id != $this->id) {
            return;
        }

        $invoice = get_post_meta($order_id, 'Xendit_invoice', true);
        $invoice_exp = get_post_meta($order_id, 'Xendit_expiry', true);
        $testMessage = '';

        if ($this->developmentmode == 'yes') {
            $testDescription = sprintf(__('<strong>TEST MODE.</strong> The bank account numbers shown below are for testing only. Real payments will not be detected.', 'woocommerce-gateway-xendit'));
            $testMessage = '<div style="text-align:left;">' . $testDescription . '</div>';
        }

        if ($invoice && $invoice_exp > time()) {
            $response = $this->xenditClass->getInvoice($invoice);
        }
    }

    public function confirmation_email($order_id)
    {
        global $wpdb, $woocommerce;

        $order = new WC_Order($order_id);
        $mailer = $woocommerce->mailer();

        $mail_body = '';

        $blog_name = html_entity_decode(get_option('blogname'), ENT_QUOTES | ENT_HTML5);
        $productinfo = "Payment for Order #{$order_id} at " . $blog_name;
        $amount = $order->order_total;
        $payer_email = $order->billing_email;
        $order_number = $this->external_id_format . "-" . $order->id;

        $log_msg = "WC_Xendit_Invoice::confirmation_email($order_id) [" . $order_number . "] {$this->xendit_status}\n\n";

        $payment_gateway = wc_get_payment_gateway_by_order($order->id);

        if ($payment_gateway->id != $this->id) {
            return;
        }

        $invoice = get_post_meta($order->id, 'Xendit_invoice', true);
        $invoice_exp = get_post_meta($order->id, 'Xendit_expiry', true);

        try {
            $log_msg .= "Start generate items and customer data\n\n";
            $additional_data = WC_Xendit_PG_Helper::generate_items_and_customer($order);
            $log_msg .= "Finish generate items and customer data\n\n";
        } catch (Exception $e) {
            $log_msg .= "Error in generating items & customer: " . $e->getMessage() . "\n\n";
        }

        $invoice_data = array(
            'external_id' => $order_number,
            'amount' => (int) $amount,
            'payer_email' => $payer_email,
            'description' => $productinfo,
            'client_type' => 'INTEGRATION',
            'success_redirect_url' => $this->get_return_url($order),
            'failure_redirect_url' => wc_get_checkout_url(),
            'platform_callback_url' => $this->xendit_callback_url,
            'checkout_redirect_flow' => $this->redirect_after,
            'items' => isset($additional_data['items']) ? $additional_data['items'] : '',
            'customer' => isset($additional_data['customer']) ? $additional_data['customer'] : ''
        );

        if ($this->time_to_live != '') {
            $invoice_data['invoice_duration'] = $this->time_to_live;
        }

        $header = array(
            'x-plugin-method' => strtoupper($this->method_code),
            'x-plugin-store-name' => $blog_name
        );

        try {
            if ($invoice && $invoice_exp > time()) {
                $response = $this->xenditClass->getInvoice($invoice);
                $log_msg .= "Order invoice expired retrieved: " . json_encode($response, JSON_PRETTY_PRINT) . "\n\n";
            } else {
                $response = $this->xenditClass->createInvoice($invoice_data, $header);
                $log_msg .= "Order invoice created: " . json_encode($response, JSON_PRETTY_PRINT) . "\n\n";
            }
        } catch (Exception $e) {
            $log_msg .= "Error in creating invoice: " . $e->getMessage() . "\n\n";
            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::INFO, true);
            throw $e;
        }

        if (isset($response['error_code'])) {
            update_post_meta($order_id, 'Xendit_error', esc_attr($response['message']));
            
            $log_msg .= "Order created error response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n\n";

            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::ERROR, true);
            return;
        }

        if ($response['status'] == 'PAID' || $response['status'] == 'COMPLETED') {
            $log_msg .= "Order status is already " . $response['status'] . "\n\n";

            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::INFO, true);
            return;
        }

        update_post_meta($order_id, 'Xendit_invoice', esc_attr($response['id']));
        update_post_meta($order_id, 'Xendit_invoice_url', esc_attr($response['invoice_url'] . '#' . $this->method_code));
        update_post_meta($order_id, 'Xendit_expiry', esc_attr(strtotime($response['expiry_date'])));

        if ($log_msg) {
            WC_Xendit_PG_Logger::log($log_msg, WC_LogDNA_Level::INFO, true);
        }

        $banks = array();
        foreach ($response['available_banks'] as $available_banks) {
            $banks[] = $available_banks;
        }

        $open_banks = count($banks);

        $mail_body .= '<table class="shop_table order_details">
            <tbody>
                <tr>
                    <td colspan="2">
                        <div style="text-align:left;">
                        ' . sprintf(__('Your order #%s has been created and waiting for payment', 'xendit'), $order->get_order_number()) . '<br />
                        <strong>' . sprintf(__('Please pay your invoice to %s this Bank Account:', 'xendit'), $open_banks > 1 ? 'one of' : '') . '</strong>
                        </div>
                    </td>
                </tr>';

        foreach ($banks as $bank) {
            $mail_body .= '
                <tr>
                    <td width="50%">
                        <div style="text-align:left;">
                        <img src="' . plugins_url('assets/images/' . strtolower($bank['bank_code']) . '.png', WC_XENDIT_PG_MAIN_FILE) . '" style="max-width:180px;width:100%;" class="img-responsive">
                        </div>
                        <div style="text-align:left;">
                        ' . sprintf(__('Bank Name: <strong>%s</strong>', 'xendit'), $bank['bank_code']) . '<br />
                        ' . sprintf(__('Account Number: <strong>%s</strong>', 'xendit'), $bank['bank_account_number']) . '<br />
                        ' . sprintf(__('Account Holder: <strong>%s</strong>', 'xendit'), $bank['account_holder_name']) . '<br />
                        ' . sprintf(__('Bank Branch: <strong>%s</strong>', 'xendit'), $bank['bank_branch']) . '<br />
                        ' . sprintf(__('Unique Amount: <strong>%s</strong>', 'xendit'), wc_price($bank['transfer_amount'])) . '<br />
                        </div>
                    </td>
                </tr>';
        }

        $mail_body .= '
                <tr>
                    <td colspan="2">
                        <div style="text-align:left;">
                            <strong>' . sprintf(__('NOTE: Please pay this before %s', 'xendit'), date("Y-m-d H:i:s", strtotime($response['expiry_date']))) . '</strong>
                        </div>
                        <div style="text-align:left;">
                            <strong><a class="button cancel" href="' . $order->get_view_order_url() . '">' . __('View my order', 'xendit') . '</a></strong>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>';

        $message = $mailer->wrap_message(__('Order confirmation', 'xendit'), $mail_body);
        return $mailer->send($order->billing_email, sprintf(__('Order #%s has been created', 'xendit'), $order->get_order_number()), $message);
    }

    public function update_status_complete($order_id)
    {
        global $wpdb, $woocommerce;

        $order = new WC_Order($order_id);

        return $this->success_payment_xendit;
    }

    public function validate_payment($response)
    {
        global $wpdb, $woocommerce;

        $order_id = $response->external_id;

        $xendit_status = $this->xendit_status;
        $log_msg = "WC_Xendit_Invoice::validate_payment() [" . $response->external_id . "] {$xendit_status}\n\n";

        if ($order_id) {
            $exploded_ext_id = explode("-", $order_id);
            $order_num = end($exploded_ext_id);

            if (!is_numeric($order_num)) {
                $exploded_ext_id = explode("_", $order_id);
                $order_num = end($exploded_ext_id);
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

            $invoice = $this->xenditClass->getInvoice($response->id);

            if (isset($invoice['error_code'])) {
                WC_Xendit_PG_Logger::log(
                    $log_msg . "Callback error in getting invoice. Error code: " . $invoice['error_code'],
                    WC_LogDNA_Level::ERROR,
                    true
                );
                header('HTTP/1.1 400 Invalid Invoice Data Received');
                exit;
            }

            if ('PAID' == $invoice['status'] || 'SETTLED' == $invoice['status']) {
                WC_Xendit_PG_Logger::log($log_msg . "Invoice is " . $invoice['status'] .", proccess order!", WC_LogDNA_Level::INFO, true);

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

                $notes = json_encode(
                    array(
                        'invoice_id' => $invoice['id'],
                        'status' => $invoice['status'],
                        'payment_method' => $invoice['payment_method'],
                        'paid_amount' => $invoice['paid_amount'],
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

                WC_Xendit_PG_Logger::log($log_msg . "Order #{$order->id} now marked as complete with Xendit!", WC_LogDNA_Level::INFO, true);

                //die(json_encode($response, JSON_PRETTY_PRINT)."\n");
                die('SUCCESS');
            } else {
                WC_Xendit_PG_Logger::log($log_msg . "Invoice status is " . $invoice['status'] . ", proccess order declined!", WC_LogDNA_Level::ERROR, true);
                
                $order->update_status('failed');

                $notes = json_encode(
                    array(
                        'invoice_id' => $invoice['id'],
                        'status' => $invoice['status'],
                        'payment_method' => $invoice['payment_method'],
                        'paid_amount' => $invoice['paid_amount'],
                    )
                );

                $note = "Xendit Payment Response:" . "{$notes}";

                $order->add_order_note('Xendit payment failed');
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

        if ($this->secret_key == "") {
            unset($gateways[$this->id]);
            return $gateways;
        }

        if ($this->id == 'xendit_gateway') {
            unset($gateways[$this->id]);
            return $gateways;
        }

        if (!$this->is_valid_for_use()) {
            unset($gateways[$this->id]);

            return $gateways;
        }
        
        $amount = WC_Xendit_PG_Helper::get_float_amount($woocommerce->cart->get_cart_total());
        if ($this->method_code === "Alfamart" && $amount > WC_Xendit_Alfamart::DEFAULT_MAXIMUM_AMOUNT) {
            unset($gateways[$this->id]);

            return $gateways;
        }

        if ($amount > WC_Xendit_Invoice::DEFAULT_MAXIMUM_AMOUNT) {
            unset($gateways[$this->id]);

            return $gateways;
        }

        if ($this->method_code !== $this->method_title) {
            $available_method = $this->get_available_methods();

            if ($available_method === array()) {
                return $gateways;
            }

            if (!in_array(strtoupper($this->method_code), $available_method)) {
                unset($gateways[$this->id]);
            }
        }

        return $gateways;
    }

    private function get_available_methods()
    {
        global $wpdb;

        if (!$available_method = wp_cache_get('available_method', 'woocommerce_xendit_pg')) {
            $invoice_settings = $this->xenditClass->getInvoiceSettings();

            if (!isset($invoice_settings['available_method'])) {
                return array();
            }

            $available_method = $invoice_settings['available_method'];
            wp_cache_add('available_method', $available_method, 'woocommerce_xendit_pg', 60);
        }

        return $available_method;
    }

    /**
     * Return filter of PG icon image in checkout page. Called by this class automatically.
     */
    public function get_icon()
    {
        if ($this->showlogo !== 'yes') {
            return;
        }

        $style = version_compare(WC()->version, '2.6', '>=') ? 'style="margin-left: 0.3em; max-height: 32px;"' : '';
        $file_name = strtolower($this->method_code) . '.png';
        $icon = '<img src="' . plugins_url('assets/images/' . $file_name, WC_XENDIT_PG_MAIN_FILE) . '" alt="Xendit" ' . $style . ' />';

        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }

    public function get_xendit_method_title()
    {
        return $this->method_type . ' - ' . $this->method_code;
    }

    public function get_xendit_method_description()
    {
        switch (strtoupper($this->method_code)) {
            case 'ALFAMART':
                return 'Bayar pesanan dengan membayar di Alfa group (Alfamart, Alfamidi & Dan+Dan) melalui <strong>Xendit</strong>';
            case 'INDOMARET':
                return WC_Xendit_Indomaret::DEFAULT_PAYMENT_DESCRIPTION;
            default:
                return 'Bayar pesanan dengan transfer bank ' . $this->method_code . ' dengan virtual account melalui <strong>Xendit</strong>';
        }
    }

    public function get_xendit_admin_description()
    {
        return sprintf(__('Collect payment from Bank Transfer %1$s on checkout page and get the report realtime on your Xendit Dashboard. <a href="%2$s" target="_blank">Sign In</a> or <a href="%3$s" target="_blank">sign up</a> on Xendit and integrate with <a href="%4$s" target="_blank">your Xendit keys</a>.', 'woocommerce-gateway-xendit'), $this->method_code, 'https://dashboard.xendit.co/auth/login', 'https://dashboard.xendit.co/register', 'https://dashboard.xendit.co/settings/developers#api-keys');
    }

    private function cancel_order($order, $note)
    {
        $order->update_status('wc-cancelled');
        $order->add_order_note($note);
    }

    public function process_admin_options()
    {
        $this->init_settings();

        $post_data = $this->get_post_data();

        foreach ($this->get_form_fields() as $key => $field) {
            if ('title' !== $this->get_field_type($field)) {
                try {
                    $this->settings[$key] = $this->get_field_value($key, $field, $post_data);
                } catch (Exception $e) {
                    $this->add_error($e->getMessage());
                }
            }
        }

        if (!isset($post_data['woocommerce_' . $this->id . '_enabled']) && $this->get_option_key() == 'woocommerce_' . $this->id . '_settings') {
            $this->settings['enabled'] = $this->enabled;
        }

        if ($post_data['woocommerce_' . $this->id . '_secret_key'] || $post_data['woocommerce_' . $this->id . '_secret_key_dev']) {
            delete_transient('cc_settings_xendit_pg');
        }

        return update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings), 'yes');
    }
}
