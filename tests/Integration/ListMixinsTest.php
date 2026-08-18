<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Integration;

use Univapay\Compat\Errors\UnivapayNoMoreItemsError;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Resources\Paginated;
use Univapay\Compat\Resources\Store;
use Univapay\Compat\Resources\Subscription;
use Univapay\Compat\Resources\Transaction;
use Univapay\Compat\Resources\TransactionToken;

/**
 * @group integration
 *
 * Round-trips every list mixin reachable from the client and from `Store` (merchant-wide AND
 * store-scoped charges/subscriptions/transaction-history/tokens), plus `getNext()`/pagination
 * shape. `Support\ListDispatcher`'s fail-loud unknown-option-key behavior is covered in
 * ListDispatcherTest (unit) instead; `listBankAccounts()`'s PERMANENT unsupported throw is
 * covered in MerchantStoreCheckoutTest.
 *
 * ## Prism getNext() limitation (documented, not fought)
 *
 * Every list operation example this suite reaches sets `has_more: false` (verified directly
 * against the spec) -- there is no way to make Prism's static mock serve a genuine SECOND page,
 * so `getNext()` is only reachable on its "no more pages" (`UnivapayNoMoreItemsError`) branch
 * here. `Resources\Paginated`'s cursor-math itself (a real next-page fetch, `getPrevious()`,
 * `reverse()`) is unit-tested offline against a fake refetch closure in
 * tests/Unit/Resources/PaginatedTest.php -- this suite only proves the REAL wire envelope
 * (`items`/`has_more`) decodes into that same, already-proven cursor machinery correctly.
 */
class ListMixinsTest extends IntegrationTestCase
{
    private function assertTerminalPage(Paginated $page): void
    {
        $this->assertFalse($page->hasMore);
        $this->expectException(UnivapayNoMoreItemsError::class);
        $page->getNext();
    }

    public function testClientListChargesReturnsTypedCharges(): void
    {
        $page = $this->storeClient()->listCharges();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(Charge::class, $item);
        }
    }

    public function testClientListStoresReturnsTypedStores(): void
    {
        $page = $this->merchantClient()->listStores();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(Store::class, $item);
        }
    }

    public function testClientListSubscriptionsReturnsTypedSubscriptions(): void
    {
        $page = $this->storeClient()->listSubscriptions();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(Subscription::class, $item);
        }
    }

    public function testClientListTransactionTokensReturnsTypedTokens(): void
    {
        $page = $this->storeClient()->listTransactionTokens();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(TransactionToken::class, $item);
        }
    }

    /**
     * `GET /transaction_history`, merchant-wide.
     */
    public function testClientListTransactionsReturnsTypedTransactions(): void
    {
        $page = $this->storeClient()->listTransactions();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(Transaction::class, $item);
        }
    }

    public function testStoreListChargesReturnsTypedCharges(): void
    {
        $store = $this->storeClient()->getStore(self::STORE_ID);

        $page = $store->listCharges();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(Charge::class, $item);
        }
    }

    public function testStoreListSubscriptionsReturnsTypedSubscriptions(): void
    {
        $store = $this->storeClient()->getStore(self::STORE_ID);

        $page = $store->listSubscriptions();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(Subscription::class, $item);
        }
    }

    /**
     * Store-scoped: `GET /stores/{storeId}/transaction_history`.
     */
    public function testStoreListTransactionsReturnsTypedTransactions(): void
    {
        $store = $this->storeClient()->getStore(self::STORE_ID);

        $page = $store->listTransactions();

        $this->assertInstanceOf(Paginated::class, $page);
        foreach ($page->items as $item) {
            $this->assertInstanceOf(Transaction::class, $item);
        }
    }

    public function testGetNextOnAMerchantWideChargeListingIsTerminal(): void
    {
        $page = $this->storeClient()->listCharges();
        $this->assertTerminalPage($page);
    }
}
