<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Xendit LogDNA
 *
 * @since 1.2.3
 */
class WC_Xendit_PG_LogDNA
{
    public static $ingestion_key = "be2ecce05cf23f7d46673940d58c5266";
    public static $url = "https://logs.logdna.com/logs/ingest";
    public static $hostname = "xendit.co";
    public static $app_name = "woocommerce-va";

    public static function get_headers()
    {
        return apply_filters(
            'woocommerce_logdna_request_headers',
            array(
                'Authorization' => 'Basic ' . base64_encode(self::$ingestion_key . ':'),
                'Content-Type' => 'application/json',
                'charset' => 'UTF-8',
            )
        );
    }

    public static function get_body( $level, $message )
    {
        $log_meta = array(
            'store_name' => get_option('blogname'),
            'plugin_version' => WC_XENDIT_PG_VERSION
        );

        return apply_filters(
            'woocommerce_logdna_request_body',
            array(
                'line' => $message,
                'app' => self::$app_name,
                'level' => $level,
                'env' => 'production',
                'meta' => $log_meta
            )
        );
    }

    /**
     * POST request to LogDNA
     *
     * @since 1.2.3
     * @version 1.2.3
     */
    public static function log( $level, $message )
    {
        $blogname = get_option('blogname');

        if (stripos($blogname, 'xendit') !== false) {
            return;
        }

        $headers = self::get_headers();
        $body = self::get_body( $level, $message );
        $now = time();

        $response = wp_safe_remote_post(
            self::$url . '?hostname=xendit.co&now=' . strtotime($now),
            array(
                'method' => 'POST',
                'headers' => $headers,
                'body' => json_encode(array(
                    'lines' => array(
                        $body
                    )
                )),
                'timeout' => 70,
                'user-agent' => 'WooCommerce ' . WC()->version,
                'blocking' => false
            )
        );

        return $response;
    }
}
