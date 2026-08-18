<?php

namespace Univapay\Compat\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use UnivaPay\Apis\CancelsApi;
use UnivaPay\Apis\ChargesApi;
use UnivaPay\Apis\RefundsApi;
use UnivaPay\Apis\StoresApi;
use UnivaPay\Apis\SubscriptionsApi;
use UnivaPay\Apis\TransactionHistoryApi;
use UnivaPay\Apis\TransactionTokensApi;
use UnivaPay\Apis\WebhooksApi;
use Univapay\Compat\Support\ListDispatcher;

/**
 * `Support\ListDispatcher` hardcodes, per endpoint, the exact positional order the REAL generated
 * `Apis\*` controller methods declare their query parameters in (see its class doc's *_ORDER
 * constants). That knowledge has no compiler-enforced link back to the generated code -- a future
 * `apimatic sdk generate` run that reorders/adds/removes a query parameter would silently make
 * `ListDispatcher` pass a value under the wrong parameter name (e.g. a `mode` filter landing in
 * the `currency` slot) with no error at all, since PHP's `...$args` spread has no way to catch a
 * mismatched *position*.
 *
 * This test is the regression guard: for every endpoint, it reflects on the REAL generated
 * method's parameter list (skipping the leading path-template parameters, which are explicit
 * `ListDispatcher` method arguments rather than `$query` keys) and asserts it matches the
 * corresponding *_ORDER constant, snake_cased. A regen that reorders anything fails THIS test
 * immediately, in an offline unit run, well before it could reach a hostile "wrong filter, wrong
 * slot" bug in production.
 *
 * Deliberately a single test method with a plain loop rather than a `@dataProvider`/
 * `#[DataProvider]`-driven test: the `#[...]` attribute syntax is PHP 8+ only (a syntax error on
 * this package's PHP 7.2 floor, which the CI lint job's `php -l` matrix includes), and the
 * doc-comment `@dataProvider` form this package's PHPUnit ^8.5..^13.0 matrix would otherwise need
 * is not honored by newer PHPUnit majors in that same range. A loop over a plain array works
 * identically everywhere in the matrix.
 */
class ListDispatcherArgOrderTest extends TestCase
{
    public function testGeneratedParamOrderMatchesTheDispatcherTableForEveryEndpoint()
    {
        // [generated class, generated method, count of leading path params to skip (explicit
        // ListDispatcher arguments, not $query keys), ListDispatcher::*_ORDER constant name
        // (null => expect an empty order, e.g. listBankTransferLedgers has no query params at all)]
        $endpoints = [
            ['listAllCharges', ChargesApi::class, 'listAllCharges', 0, 'CHARGE_LIST_ORDER'],
            ['listStoreCharges', ChargesApi::class, 'listStoreCharges', 1, 'CHARGE_LIST_ORDER'],
            ['listStores', StoresApi::class, 'listStores', 0, 'STORE_LIST_ORDER'],
            ['listAllSubscriptions', SubscriptionsApi::class, 'listAllSubscriptions', 0, 'SUBSCRIPTION_LIST_ALL_ORDER'],
            [
                'listStoreSubscriptions',
                SubscriptionsApi::class,
                'listStoreSubscriptions',
                1,
                'SUBSCRIPTION_LIST_STORE_ORDER',
            ],
            ['listSubscriptionCharges', SubscriptionsApi::class, 'listSubscriptionCharges', 3, 'CURSOR_ONLY_ORDER'],
            ['listSubscriptionPayments', SubscriptionsApi::class, 'listSubscriptionPayments', 2, 'CURSOR_ONLY_ORDER'],
            [
                'listChargesForSubscriptionPayment',
                SubscriptionsApi::class,
                'listChargesForSubscriptionPayment',
                3,
                'CURSOR_ONLY_ORDER',
            ],
            [
                'listAllTransactionTokens',
                TransactionTokensApi::class,
                'listAllTransactionTokens',
                0,
                'TOKEN_LIST_ORDER',
            ],
            [
                'listStoreTransactionTokens',
                TransactionTokensApi::class,
                'listStoreTransactionTokens',
                1,
                'TOKEN_LIST_ORDER',
            ],
            ['listRefunds', RefundsApi::class, 'listRefunds', 2, 'REFUND_LIST_ORDER'],
            ['listCancels', CancelsApi::class, 'listCancels', 2, 'CURSOR_ONLY_ORDER'],
            ['listWebhooks', WebhooksApi::class, 'listWebhooks', 1, 'WEBHOOK_LIST_ORDER'],
            ['listWebhookEvents', WebhooksApi::class, 'listWebhookEvents', 2, 'CURSOR_ONLY_ORDER'],
            ['listBankTransferLedgers', ChargesApi::class, 'listBankTransferLedgers', 2, null],
            [
                'listTransactionHistory',
                TransactionHistoryApi::class,
                'listTransactionHistory',
                0,
                'TRANSACTION_HISTORY_ORDER',
            ],
            [
                'listStoreTransactionHistory',
                TransactionHistoryApi::class,
                'listStoreTransactionHistory',
                1,
                'TRANSACTION_HISTORY_ORDER',
            ],
        ];

        $dispatcherClass = new ReflectionClass(ListDispatcher::class);
        $assertionCount = 0;

        foreach ($endpoints as list($label, $apiClass, $method, $skipLeadingPathParams, $orderConstant)) {
            $reflectedMethod = new ReflectionMethod($apiClass, $method);
            $paramNames = array_map(function ($p) {
                return $p->getName();
            }, $reflectedMethod->getParameters());

            $queryParamNames = array_slice($paramNames, $skipLeadingPathParams);
            $actualOrder = array_map([self::class, 'snakeCase'], $queryParamNames);

            $expectedOrder = $orderConstant === null ? [] : $dispatcherClass->getConstant($orderConstant);

            $message = "$label ($apiClass::$method) query parameter order drifted";
            $this->assertSame($expectedOrder, $actualOrder, $message);
            $assertionCount++;
        }

        $this->assertSame(count($endpoints), $assertionCount);
    }

    private static function snakeCase(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
