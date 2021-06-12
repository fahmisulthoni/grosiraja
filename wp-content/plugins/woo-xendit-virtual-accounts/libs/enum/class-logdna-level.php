<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Xendit LogDNA
 *
 * @since 1.3.6
 */
class WC_LogDNA_Level
{
    const __default = self::INFO;
    
    const INFO = 'INFO';
    const ERROR = 'ERROR';
    const CRITICAL = 'CRITICAL';
}
