<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Resources;

use Money\Currency;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\BankAccountStatus;
use Univapay\Compat\Enums\BankAccountType;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\BankAccount;

/**
 * Covers `BankAccount`'s PERMANENT unsupported status: merchant payout bank accounts are not
 * exposed by the new engine SDK. Hydration (`getSchema()->parse()`) is still a real, working
 * data-class capability -- see class doc for why -- only `fetch()`/`update()` throw.
 */
class BankAccountTest extends TestCase
{
    private function bankAccountJson(array $overrides = []): array
    {
        return array_replace([
            'id' => 'bank-account-1',
            'primary' => true,
            'holder_name' => 'Taro Yamada',
            'bank_name' => 'Test Bank',
            'branch_name' => 'Main Branch',
            'country' => 'JP',
            'bank_address' => null,
            'currency' => 'JPY',
            'account_number' => 'XXXXX123',
            'routing_number' => null,
            'swift_code' => null,
            'ifsc_code' => null,
            'routing_code' => null,
            'last_four' => '0123',
            'status' => 'verified',
            'account_type' => 'savings',
            'created_on' => '2022-08-01T00:00:00.000000Z',
        ], $overrides);
    }

    private function parse(array $json): BankAccount
    {
        return BankAccount::getSchema()->parse($json);
    }

    public function testHydratesABankAccount()
    {
        $bankAccount = $this->parse($this->bankAccountJson());

        $this->assertSame('bank-account-1', $bankAccount->id);
        $this->assertTrue($bankAccount->primary);
        $this->assertSame('Taro Yamada', $bankAccount->holderName);
        $this->assertEquals(new Currency('JPY'), $bankAccount->currency);
        $this->assertEquals(BankAccountStatus::VERIFIED(), $bankAccount->status);
        $this->assertEquals(BankAccountType::SAVINGS(), $bankAccount->accountType);
    }

    public function testFetchThrowsPermanently()
    {
        $bankAccount = $this->parse($this->bankAccountJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);
        $bankAccount->fetch();
    }

    public function testUpdateThrowsPermanently()
    {
        $bankAccount = $this->parse($this->bankAccountJson());

        $this->expectException(UnivapayUnsupportedFeatureError::class);
        $bankAccount->update(['holder_name' => 'New Name']);
    }
}
