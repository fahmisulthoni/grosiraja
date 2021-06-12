<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/class-wc-xendit-helper.php';

final class HelperTest extends TestCase
{
    public function testShouldHandleNoSeparator(): void
    {
        $this->assertEquals(
            '20000',
            WC_Xendit_PG_Helper::get_float_amount('Rp20000')
        );
    }

    public function testShouldHandlePointSeparator(): void
    {
        $this->assertEquals(
            '20000',
            WC_Xendit_PG_Helper::get_float_amount('Rp20.000')
        );
    }

    public function testShouldHandleCommaSeparator(): void
    {
        $this->assertEquals(
            '20000',
            WC_Xendit_PG_Helper::get_float_amount('Rp20,000')
        );
    }

    public function testShouldHandleSeparatedLeftCurrency(): void
    {
        $this->assertEquals(
            '20000',
            WC_Xendit_PG_Helper::get_float_amount('Rp 20,000')
        );
    }

    public function testShouldHandleRightCurrency(): void
    {
        $this->assertEquals(
            '20000',
            WC_Xendit_PG_Helper::get_float_amount('20,000Rp')
        );
    }

    public function testShouldHandleSeparatedRightCurrency(): void
    {
        $this->assertEquals(
            '20000',
            WC_Xendit_PG_Helper::get_float_amount('20,000 Rp')
        );
    }

    public function testShouldHandlePointDecimal(): void
    {
        $this->assertEquals(
            '20000',
            WC_Xendit_PG_Helper::get_float_amount('Rp20,000.00')
        );
    }

    public function testShouldHandleCommaDecimal(): void
    {
        $this->assertEquals(
            '20000',
            WC_Xendit_PG_Helper::get_float_amount('Rp20,000,00')
        );
    }

    public function testShouldHandlePointCurrency(): void
    {
        $this->assertEquals(
            '20000',
            WC_Xendit_PG_Helper::get_float_amount('Rp.20,000,00')
        );
    }
}