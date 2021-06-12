<?php

if (!defined('ABSPATH')) {
    exit;
}

final class WC_Xendit_PG_Helper {
    static public function get_float_amount($raw_amount) {
        $clean_string = preg_replace('/([^0-9\.,])/i', '', $raw_amount);
        $only_numbers_string = preg_replace('/([^0-9])/i', '', $raw_amount);

        $separators_count_to_be_erased = strlen($clean_string) - strlen($only_numbers_string) - 1;

        $string_with_separator = preg_replace('/([,\.])/', '', $clean_string, $separators_count_to_be_erased);
        $removed_thousand_separator = preg_replace('/(\.|,)(?=[0-9]{3,}$)/', '',  $string_with_separator);

        return (float) str_replace(',', '.', $removed_thousand_separator);
    }

    public static function is_wc_lt( $version ) {
        return version_compare( WC_VERSION, $version, '<' );
    }

    public static function generate_items_and_customer( $order ) {
        global $woocommerce;

        if (!is_object($order)) {
            return;
        }
        
        $items = array();
        foreach ($order->get_items() AS $item_data) {           
            if (!is_object($item_data)) {
                break;
            }
 
            // Get an instance of WC_Product object
            $product = $item_data->get_product();

            $item = array();
            $item['id']         = $product->get_id();
            $item['name']       = $product->get_name();
            $item['price']      = $product->get_price();
            $item['category']   = $product->get_type();
            $item['url']        = get_permalink($product->get_id());
            $item['quantity']   = $item_data->get_quantity();
            
            array_push($items, json_encode(array_map('strval', $item)));
        }

        $customer = array();
        $customer['full_name']              = $order->billing_first_name . ' ' . $order->billing_last_name;
        $customer['email']                  = $order->billing_email;
        $customer['phone_number']           = $order->billing_phone;
        $customer['address_city']           = $order->shipping_city ? $order->shipping_city : $order->billing_city;
        $customer['address_postal_code']    = $order->shipping_postcode ? $order->shipping_postcode : $order->billing_postcode;

        return array(
            'items' => '[' . implode(",", $items) . ']',
            'customer' => json_encode($customer)
        );
    }
}