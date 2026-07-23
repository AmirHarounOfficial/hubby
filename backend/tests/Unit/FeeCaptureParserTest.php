<?php

namespace Tests\Unit;

use App\Models\OrderFee;
use App\Services\Profit\FeeCapture\AmazonFeeParser;
use App\Services\Profit\FeeCapture\ShopifyFeeParser;
use PHPUnit\Framework\TestCase;

/**
 * The pure fee parsers (A3). These convert raw marketplace payloads into normalised, signed fees
 * without touching the network, so they're the piece worth pinning down against sample responses.
 */
class FeeCaptureParserTest extends TestCase
{
    public function test_amazon_flips_the_sign_and_maps_fee_types(): void
    {
        // SP-API reports fees as negative amounts (money leaving the seller).
        $events = [
            'ShipmentEventList' => [[
                'AmazonOrderId' => '111-222',
                'ShipmentFeeList' => [
                    ['FeeType' => 'ShippingChargeback', 'FeeAmount' => ['CurrencyCode' => 'SAR', 'CurrencyAmount' => -12.5]],
                ],
                'ShipmentItemList' => [[
                    'OrderItemId' => 'itm-1',
                    'ItemFeeList' => [
                        ['FeeType' => 'Commission', 'FeeAmount' => ['CurrencyCode' => 'SAR', 'CurrencyAmount' => -30.0]],
                        ['FeeType' => 'FBAPerUnitFulfillmentFee', 'FeeAmount' => ['CurrencyCode' => 'SAR', 'CurrencyAmount' => -15.0]],
                        ['FeeType' => 'ZeroFee', 'FeeAmount' => ['CurrencyCode' => 'SAR', 'CurrencyAmount' => 0]],
                    ],
                ]],
            ]],
        ];

        $fees = AmazonFeeParser::parse($events);

        $this->assertCount(3, $fees); // the zero fee is dropped
        $byType = collect($fees)->keyBy('subtype');

        $this->assertSame(OrderFee::TYPE_COMMISSION, $byType['Commission']['type']);
        $this->assertSame('30.0000', $byType['Commission']['amount']); // sign flipped to a positive cost
        $this->assertSame(OrderFee::TYPE_FULFILMENT, $byType['FBAPerUnitFulfillmentFee']['type']);
        $this->assertSame(OrderFee::TYPE_SHIPPING, $byType['ShippingChargeback']['type']);
        $this->assertSame('SAR', $byType['Commission']['currency']);
    }

    public function test_amazon_returns_nothing_for_an_empty_event_set(): void
    {
        $this->assertSame([], AmazonFeeParser::parse([]));
    }

    public function test_shopify_extracts_processing_fees_from_successful_captures_only(): void
    {
        $transactions = [
            [
                'id' => 9001, 'kind' => 'sale', 'status' => 'success', 'gateway' => 'shopify_payments',
                'currency' => 'SAR', 'processed_at' => '2026-06-15T10:30:00Z',
                'fees' => [
                    ['type' => 'transaction', 'amount' => ['amount' => '13.31', 'currency_code' => 'SAR']],
                ],
            ],
            // A failed transaction contributes nothing.
            ['id' => 9002, 'kind' => 'sale', 'status' => 'failure', 'fees' => [['amount' => ['amount' => '9.99']]]],
            // A refund is not a capture — skipped here.
            ['id' => 9003, 'kind' => 'refund', 'status' => 'success', 'fees' => [['amount' => ['amount' => '2.00']]]],
        ];

        $fees = ShopifyFeeParser::parse($transactions);

        $this->assertCount(1, $fees);
        $this->assertSame(OrderFee::TYPE_PAYMENT, $fees[0]['type']);
        $this->assertSame('13.3100', $fees[0]['amount']); // already a positive cost — no flip
        $this->assertSame('SAR', $fees[0]['currency']);
        $this->assertSame('2026-06-15T10:30:00Z', $fees[0]['posted_at']);
    }

    public function test_shopify_handles_a_transaction_with_no_fees_array(): void
    {
        $transactions = [
            ['id' => 1, 'kind' => 'capture', 'status' => 'success', 'gateway' => 'manual'],
        ];

        $this->assertSame([], ShopifyFeeParser::parse($transactions));
    }
}
