<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use Money\Currency;
use Money\Money;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\TransferStatus;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\Transfer;

/**
 * Covers `Transfer`: hydration (the shape webhook transfer events must still parse
 * correctly into, per `UnivapayClientWebhookTest`) plus every HTTP-touching surface throwing
 * `UnivapayUnsupportedFeatureError` PERMANENTLY (`fetch()`/`update()` inherited from `Resource`,
 * `listLedgers()`/`listStatusChanges()` via the mixins -- already throw unconditionally,
 * re-asserted here through a real hydrated instance for end-to-end confidence).
 */
class TransferTest extends TestCase
{
    private function transferJson(array $overrides = []): array
    {
        return array_replace([
            'id' => 'transfer-1',
            'bank_account_id' => 'bank-account-1',
            'currency' => 'JPY',
            'amount' => 10000,
            'amount_formatted' => 10000,
            'status' => 'paid',
            'error_code' => null,
            'error_text' => null,
            'metadata' => [],
            'note' => null,
            'from' => '2022-07-01T00:00:00.000000Z',
            'to' => '2022-07-31T00:00:00.000000Z',
            'created_on' => '2022-08-01T00:00:00.000000Z',
        ], $overrides);
    }

    private function parseTransfer(array $json): Transfer
    {
        return Transfer::getSchema()->parse($json);
    }

    public function testHydratesATransfer()
    {
        $transfer = $this->parseTransfer($this->transferJson());

        $this->assertSame('transfer-1', $transfer->id);
        $this->assertSame('bank-account-1', $transfer->bankAccountId);
        $this->assertEquals(new Money(10000, new Currency('JPY')), $transfer->amount);
        $this->assertEquals(TransferStatus::PAID(), $transfer->status);
        $this->assertEquals(date_create('2022-07-01T00:00:00.000000Z'), $transfer->from);
        $this->assertEquals(date_create('2022-08-01T00:00:00.000000Z'), $transfer->createdOn);
    }

    public function testFetchThrowsUnsupportedPermanently()
    {
        $transfer = $this->parseTransfer($this->transferJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $transfer->fetch();
    }

    public function testUpdateThrowsUnsupportedPermanently()
    {
        $transfer = $this->parseTransfer($this->transferJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $transfer->update(['note' => 'x']);
    }

    public function testListLedgersThrowsUnsupportedPermanently()
    {
        $transfer = $this->parseTransfer($this->transferJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $transfer->listLedgers();
    }

    public function testListStatusChangesThrowsUnsupportedPermanently()
    {
        $transfer = $this->parseTransfer($this->transferJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $transfer->listStatusChanges();
    }
}
