<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Integration;

use DateTime;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Errors\UnivapaySDKError;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\CheckoutInfo;
use Univapay\Compat\Resources\Merchant;
use Univapay\Compat\Resources\Store;

/**
 * @group integration
 *
 * Round-trips: getMe(), getStore(), getCheckoutInfo(). All merchant/store-level GETs -- no
 * create/mutate calls in this file. `getBankAccount()`/`listBankAccounts()` are PERMANENTLY
 * unsupported (bank accounts are out of the spec entirely) and asserted below as throws -- no
 * Prism round trip needed since neither ever reaches HTTP.
 */
class MerchantStoreCheckoutTest extends IntegrationTestCase
{
    public function testGetMeReturnsAHydratedMerchantWithTypedFields(): void
    {
        $merchant = $this->merchantClient()->getMe();

        $this->assertInstanceOf(Merchant::class, $merchant);
        $this->assertNotNull($merchant->id);
        $this->assertInstanceOf(DateTime::class, $merchant->createdOn);
        // `verified` is a plain bool per the spec, not a TypedEnum -- confirms no over-eager
        // enum-ification happened on a boolean field.
        $this->assertIsBool($merchant->verified);
    }

    public function testGetStoreReturnsAHydratedStoreWithTypedConfiguration(): void
    {
        $store = $this->storeClient()->getStore(self::STORE_ID);

        $this->assertInstanceOf(Store::class, $store);
        // NOT asserted against self::STORE_ID: Prism serves its own example body verbatim
        // regardless of the requested path param's value (see ChargeTest's identical note).
        $this->assertNotEmpty($store->id);
        $this->assertInstanceOf(DateTime::class, $store->createdOn);
        // configuration is optional (upsert(..., false, ...)) -- when Prism's example includes
        // it, it must hydrate as the real Configuration object, not a bare array.
        if ($store->configuration !== null) {
            $this->assertInstanceOf(
                \Univapay\Compat\Resources\Configuration\Configuration::class,
                $store->configuration
            );
        }
    }

    public function testGetCheckoutInfoReturnsATypedCheckoutInfo(): void
    {
        $checkoutInfo = $this->storeClient()->getCheckoutInfo();

        $this->assertInstanceOf(CheckoutInfo::class, $checkoutInfo);
        $this->assertInstanceOf(AppTokenMode::class, $checkoutInfo->mode);
    }

    public function testGetCheckoutInfoRequiresAStoreAppToken(): void
    {
        // Support\Bridge::requireStoreId()'s guard -- fires pre-HTTP for a merchant-level token,
        // matching old getStoreBasedContext() parity.
        $this->expectException(UnivapaySDKError::class);

        $this->merchantClient()->getCheckoutInfo();
    }

    public function testGetBankAccountThrowsPermanently(): void
    {
        // PERMANENT unsupported (bank accounts are out of the spec entirely) -- no generated
        // BankAccountsApi exists to dispatch to, so this never reaches Prism/HTTP at all.
        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $this->storeClient()->getBankAccount('bank-account-1');
    }

    public function testListBankAccountsThrowsPermanently(): void
    {
        $this->expectException(UnivapayUnsupportedFeatureError::class);

        $this->storeClient()->listBankAccounts();
    }
}
