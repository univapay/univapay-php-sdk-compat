<?php

namespace Univapay\Compat\Tests\Unit\Resources\Mixins;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\Mixins\GetBankAccounts;
use Univapay\Compat\Resources\Mixins\GetLedgers;
use Univapay\Compat\Resources\Mixins\GetStatusChanges;
use Univapay\Compat\Resources\Mixins\GetTransfers;
use Univapay\Compat\Tests\Support\NoticesDisabledBridgeStub;

/**
 * `GetTransfers`, `GetLedgers`, `GetStatusChanges`, `GetBankAccounts` are UNSUPPORTED
 * (`GetTransfers` et al. because the new transport engine has no Transfers API at all;
 * `GetBankAccounts` because the merchant-payout Bank Accounts feature is out of the spec
 * entirely): every method throws unconditionally, both the positional and `...ByOptions()` forms,
 * without building a query or reaching any dispatcher.
 */
class UnsupportedMixinsTest extends TestCase
{
    public function testGetTransfersThrowsOnBothMethodForms()
    {
        $fixture = new GetTransfersFixture();

        try {
            $fixture->listTransfers();
            $this->fail('Expected UnivapayUnsupportedFeatureError');
        } catch (UnivapayUnsupportedFeatureError $e) {
            $this->assertStringContainsString('Transfer', $e->getMessage());
        }

        $this->expectException(UnivapayUnsupportedFeatureError::class);
        $fixture->listTransfersByOptions();
    }

    public function testGetLedgersThrowsOnBothMethodForms()
    {
        $fixture = new GetLedgersFixture();

        try {
            $fixture->listLedgers();
            $this->fail('Expected UnivapayUnsupportedFeatureError');
        } catch (UnivapayUnsupportedFeatureError $e) {
            $this->assertStringContainsString('ledgers', $e->getMessage());
        }

        $this->expectException(UnivapayUnsupportedFeatureError::class);
        $fixture->listLedgersByOptions();
    }

    public function testGetStatusChangesThrowsOnBothMethodForms()
    {
        $fixture = new GetStatusChangesFixture();

        try {
            $fixture->listStatusChanges();
            $this->fail('Expected UnivapayUnsupportedFeatureError');
        } catch (UnivapayUnsupportedFeatureError $e) {
            $this->assertStringContainsString('status changes', $e->getMessage());
        }

        $this->expectException(UnivapayUnsupportedFeatureError::class);
        $fixture->listStatusChangesByOptions();
    }

    public function testGetBankAccountsThrowsOnBothMethodForms()
    {
        $fixture = new GetBankAccountsFixture();

        try {
            $fixture->listBankAccounts();
            $this->fail('Expected UnivapayUnsupportedFeatureError');
        } catch (UnivapayUnsupportedFeatureError $e) {
            $this->assertStringContainsString('Bank account', $e->getMessage());
        }

        // listBankAccountContextsByOptions(), not listBankAccountsByOptions() -- see
        // GetBankAccounts's class doc on why the old typo'd name is preserved verbatim.
        $this->expectException(UnivapayUnsupportedFeatureError::class);
        $fixture->listBankAccountContextsByOptions();
    }
}

class GetTransfersFixture
{
    use GetTransfers;

    protected function getBridge()
    {
        return new NoticesDisabledBridgeStub();
    }
}

class GetLedgersFixture
{
    use GetLedgers;

    protected function getBridge()
    {
        return new NoticesDisabledBridgeStub();
    }
}

class GetStatusChangesFixture
{
    use GetStatusChanges;

    protected function getBridge()
    {
        return new NoticesDisabledBridgeStub();
    }
}

class GetBankAccountsFixture
{
    use GetBankAccounts;

    protected function getBridge()
    {
        return new NoticesDisabledBridgeStub();
    }
}
