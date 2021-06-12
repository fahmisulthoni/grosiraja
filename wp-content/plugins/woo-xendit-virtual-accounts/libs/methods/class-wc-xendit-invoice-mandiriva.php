<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Xendit_MandiriVA extends WC_Xendit_Invoice {
    public function __construct() {
        parent::__construct();

        $this->id           = 'xendit_mandiriva';

        // Load the form fields.
		$this->init_form_fields();

		// Load the settings.
        $this->init_settings();
        
        $this->enabled = $this->get_option( 'enabled' );

        $this->method_type = 'Bank Transfer';
        $this->method_code = 'Mandiri';
        $this->title = !empty($this->get_option('channel_name')) ? $this->get_option('channel_name') : $this->get_xendit_method_title();
        $this->description = !empty($this->get_option('payment_description')) ? nl2br($this->get_option('payment_description')) : $this->get_xendit_method_description();

		$this->method_title = __( 'Xendit Mandiri VA', 'woocommerce-gateway-xendit' );
        $this->method_description = $this->get_xendit_admin_description();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields() {
		$this->form_fields = require( WC_XENDIT_PG_PLUGIN_PATH . '/libs/forms/wc-xendit-invoice-mandiriva-settings.php' );
    }
    
    public function admin_options() {
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
}