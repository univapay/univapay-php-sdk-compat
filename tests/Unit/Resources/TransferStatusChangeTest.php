<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\TransferStatus;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\TransferStatusChange;

/**
 * Covers `TransferStatusChange`: hydration + permanent `fetch()`/`update()` throws
 * (same rationale as `Transfer` -- see its class doc; items of this shape only ever arrive
 * already-hydrated inside a `Transfer::listStatusChanges()` page, which itself throws
 * unconditionally).
 */
class TransferStatusChangeTest extends TestCase
{
    private function statusChangeJson(array $overrides = []): array
    {
        return array_replace([
            'id' => 'status-change-1',
            'merchant_id' => 'merchant-1',
            'transfer_id' => 'transfer-1',
            'old_status' => 'processing',
            'new_status' => 'paid',
            'reason' => null,
            'created_on' => '2022-08-01T00:00:00.000000Z',
        ], $overrides);
    }

    private function parse(array $json): TransferStatusChange
    {
        return TransferStatusChange::getSchema()->parse($json);
    }

    public function testHydratesATransferStatusChange()
    {
        $statusChange = $this->parse($this->statusChangeJson());

        $this->assertSame('status-change-1', $statusChange->id);
        $this->assertSame('merchant-1', $statusChange->merchantId);
        $this->assertSame('transfer-1', $statusChange->transferId);
        $this->assertEquals(TransferStatus::PROCESSING(), $statusChange->oldStatus);
        $this->assertEquals(TransferStatus::PAID(), $statusChange->newStatus);
    }

    public function testFetchThrowsUnsupportedPermanently()
    {
        $statusChange = $this->parse($this->statusChangeJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $statusChange->fetch();
    }

    public function testUpdateThrowsUnsupportedPermanently()
    {
        $statusChange = $this->parse($this->statusChangeJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $statusChange->update(['reason' => 'x']);
    }
}
