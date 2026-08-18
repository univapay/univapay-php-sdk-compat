<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

use Univapay\Compat\Errors\UnivapayListDispatchError;
use Univapay\Compat\Resources\Paginated;

/**
 * @internal
 *
 * Replaces the old SDK's `Utility\RequesterUtils::executeGetPaginated()`. Old list mixins
 * (`Resources\Mixins\Get*`) built a snake_case query array and handed it, together with a
 * `jsonableClass` and a `Requests\RequestContext`, to that one shared function, which issued a
 * raw GET against the context's URL and parsed the response. The new transport engine's generated
 * `Apis\*` controllers take the SAME filters as POSITIONAL arguments instead of a query string, so
 * there is no single shared "raw GET" primitive left to delegate to -- each endpoint needs its own
 * translation from the old snake_case query dict to the generated method's exact argument order.
 * This class is that translation table, one static method per endpoint.
 *
 * Fail-loud contract: the OLD SDK's `Utility\OptionsValidator::validate()` passed any key it had
 * no validation rule for straight through into the query array, which old `executeGetPaginated()`
 * then forwarded unmodified to the server -- e.g. an unrecognized `email` filter would be silently
 * dropped by a server that doesn't understand it, and the caller would get back an unfiltered list
 * with no indication anything was ignored. This compat layer never does that: every endpoint
 * method here knows the COMPLETE set of query keys the generated controller method accepts (in
 * order), and:
 *
 * - a key present in `$query` that IS in that known set is passed through positionally;
 * - a key present in `$query` that is NOT in that known set throws `UnivapayListDispatchError`
 *   (a `UnivapaySDKError`) naming the key and the endpoint -- either because the new spec has no
 *   equivalent filter yet (a permanent gap, e.g. `card_number` on charge listing) or because the
 *   endpoint itself has no generated controller method at all (bank account listing --
 *   PERMANENTLY unsupported; see `Mixins\GetBankAccounts`, which throws unconditionally before
 *   ever reaching this dispatcher);
 * - a key ABSENT from `$query` (the caller's mixin already ran `FunctionalUtils::stripNulls()`
 *   before calling in here, so "absent" means "the user never set this") is passed as a literal
 *   `null` positional argument -- NOT the generated method's own PHP default (e.g. `limit = 10`,
 *   `cursorDirection = CursorDirectionQuery::DESC`). Passing the PHP default instead of `null`
 *   would put a `limit=10`/`cursor_direction=desc` on the wire even when the caller never asked
 *   for one, which is not what the old SDK ever did (it only ever sent a filter it was explicitly
 *   given) and would silently change `getNext()`/`getPrevious()` replay behavior. Explicit `null`
 *   mirrors the generated code's own `QueryParam::init($name, null)`, which omits the parameter
 *   from the request entirely -- so the wire behavior is identical to the parameter never
 *   existing.
 *
 * Every endpoint method returns a `Paginated` built from the ApiCaller-decoded raw response body
 * (never the generated SDK's typed result -- see `ApiCaller`'s "raw-body hydration" doc) via the
 * `callable $itemParser` the caller supplies (in practice, a resource's ported `Jsonable` schema
 * parser bound to its `CompatContext`). The `Paginated`'s replay `$fetcher` closure recurses back
 * into the SAME dispatcher method, so `getNext()`/`getPrevious()` keep going through this exact
 * translation (and its fail-loud checks) on every subsequent page.
 */
final class ListDispatcher
{
    // --- Known query-key orders, one per endpoint, in the EXACT positional order the generated
    // controller method declares them (excluding path parameters, which are explicit method
    // arguments below, not query keys). Verified against sdk/php/src/Apis/*.php; see
    // tests/Unit/Support/ListDispatcherArgOrderTest.php, which reflects on the REAL generated
    // classes so a future `apimatic sdk generate` reordering these fails loudly here instead of
    // silently sending filters under the wrong parameter.

    private const CHARGE_LIST_ORDER = [
        'limit', 'cursor', 'cursor_direction', 'last_four', 'name', 'exp_month', 'exp_year',
        'from', 'to', 'email', 'phone', 'amount_from', 'amount_to', 'currency', 'mode',
        'metadata', 'transaction_token_id',
    ];

    private const STORE_LIST_ORDER = ['limit', 'cursor', 'cursor_direction', 'short_id', 'search'];

    /**
     * `SubscriptionsApi::listAllSubscriptions()` (the merchant-wide endpoint) accepts `search`/
     * `status`/`mode` filters in addition to cursor params -- the same query-parameter shape as
     * `listStoreSubscriptions()` (below).
     */
    private const SUBSCRIPTION_LIST_ALL_ORDER =
        ['search', 'status', 'mode', 'limit', 'cursor', 'cursor_direction'];

    private const SUBSCRIPTION_LIST_STORE_ORDER =
        ['search', 'status', 'mode', 'limit', 'cursor', 'cursor_direction'];

    private const CURSOR_ONLY_ORDER = ['limit', 'cursor', 'cursor_direction'];

    private const REFUND_LIST_ORDER = ['limit', 'cursor', 'cursor_direction', 'metadata'];

    private const WEBHOOK_LIST_ORDER = ['limit', 'cursor', 'cursor_direction', 'active'];

    /**
     * `TransactionTokensApi::listAllTransactionTokens()`/`listStoreTransactionTokens()` accept
     * `search`/`customer_id`/`type`/`mode`/`active` filters in addition to cursor params.
     * `Mixins\GetTransactionTokens` builds the query array for all five.
     */
    private const TOKEN_LIST_ORDER =
        ['search', 'customer_id', 'type', 'mode', 'active', 'limit', 'cursor', 'cursor_direction'];

    /**
     * `TransactionHistoryApi::listTransactionHistory()`/`listStoreTransactionHistory()`'s full
     * query-parameter order. Old `Mixins\GetTransactions::listTransactions()` (positional,
     * epoch-millis dates) and `::listTransactionsByOptions()` (snake_case array, ATOM dates) both
     * only ever build a SUBSET of these keys (`mode`, `from`, `to`, `status`, `type`, `search`,
     * `metadata`, `cursor`, `limit`, `cursor_direction` -- see that trait's class doc); every other
     * key here (`short_id`, `email`, `id`, `card_exp`, `card_last_four`, `cardholder`,
     * `card_brand`/`brand`/`brands`, `currency`, `service_provider`/`service_providers`,
     * `gateway_transaction_id`, `bank_transfer_payment_statuses`,
     * `bank_transfer_latest_deposit_date_from`/`_to` -- NOTE: the actual WIRE query keys for these
     * last two are dotted, `bank_transfer_latest_deposit_date.from`/`.to`
     * (`QueryParam::init('bank_transfer_latest_deposit_date.from', $bankTransferLatestDepositDateFrom)`
     * in the generated controller) -- the underscore form here is this table's OWN key naming
     * (matching `ListDispatcherArgOrderTest`'s snake_case-of-the-PHP-parameter-name convention,
     * the same convention every other key in this file already follows), not the wire key) has no
     * old-SDK mixin surface to reach it through at all today -- included here only so
     * `buildArgs()`'s positional mapping stays correct against the REAL generated signature
     * (verified by `ListDispatcherArgOrderTest`), not because old callers can supply them yet.
     */
    private const TRANSACTION_HISTORY_ORDER = [
        'mode', 'short_id', 'from', 'to', 'status', 'type', 'search', 'email', 'id', 'metadata',
        'card_exp', 'card_last_four', 'cardholder', 'card_brand', 'brand', 'brands', 'currency',
        'service_provider', 'service_providers', 'gateway_transaction_id',
        'bank_transfer_payment_statuses', 'bank_transfer_latest_deposit_date_from',
        'bank_transfer_latest_deposit_date_to', 'limit', 'cursor', 'cursor_direction',
    ];

    // --- Charges -------------------------------------------------------------------------------

    /**
     * @param Bridge $bridge
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listAllCharges(Bridge $bridge, array $query, callable $itemParser): Paginated
    {
        $args = self::buildArgs($query, self::CHARGE_LIST_ORDER, 'listAllCharges');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $args) {
                return $bridge->charges()->listAllCharges(...$args);
            },
            $bridge->handlers(),
            'GET /charges'
        );
        return self::wrapPage($decoded, $query, $itemParser, function (array $newQuery) use ($bridge, $itemParser) {
            return self::listAllCharges($bridge, $newQuery, $itemParser);
        });
    }

    /**
     * @param Bridge $bridge
     * @param string $storeId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listStoreCharges(
        Bridge $bridge,
        string $storeId,
        array $query,
        callable $itemParser
    ): Paginated {
        $args = self::buildArgs($query, self::CHARGE_LIST_ORDER, 'listStoreCharges');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $storeId, $args) {
                return $bridge->charges()->listStoreCharges($storeId, ...$args);
            },
            $bridge->handlers(),
            "GET /stores/$storeId/charges"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $itemParser) {
                return self::listStoreCharges($bridge, $storeId, $newQuery, $itemParser);
            }
        );
    }

    // --- Stores ----------------------------------------------------------------------------------

    /**
     * @param Bridge $bridge
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listStores(Bridge $bridge, array $query, callable $itemParser): Paginated
    {
        $args = self::buildArgs($query, self::STORE_LIST_ORDER, 'listStores');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $args) {
                return $bridge->stores()->listStores(...$args);
            },
            $bridge->handlers(),
            'GET /stores'
        );
        return self::wrapPage($decoded, $query, $itemParser, function (array $newQuery) use ($bridge, $itemParser) {
            return self::listStores($bridge, $newQuery, $itemParser);
        });
    }

    // --- Subscriptions -----------------------------------------------------------------------

    /**
     * @param Bridge $bridge
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listAllSubscriptions(Bridge $bridge, array $query, callable $itemParser): Paginated
    {
        $args = self::buildArgs($query, self::SUBSCRIPTION_LIST_ALL_ORDER, 'listAllSubscriptions');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $args) {
                return $bridge->subscriptions()->listAllSubscriptions(...$args);
            },
            $bridge->handlers(),
            'GET /subscriptions'
        );
        return self::wrapPage($decoded, $query, $itemParser, function (array $newQuery) use ($bridge, $itemParser) {
            return self::listAllSubscriptions($bridge, $newQuery, $itemParser);
        });
    }

    /**
     * @param Bridge $bridge
     * @param string $storeId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listStoreSubscriptions(
        Bridge $bridge,
        string $storeId,
        array $query,
        callable $itemParser
    ): Paginated {
        $args = self::buildArgs($query, self::SUBSCRIPTION_LIST_STORE_ORDER, 'listStoreSubscriptions');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $storeId, $args) {
                return $bridge->subscriptions()->listStoreSubscriptions($storeId, ...$args);
            },
            $bridge->handlers(),
            "GET /stores/$storeId/subscriptions"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $itemParser) {
                return self::listStoreSubscriptions($bridge, $storeId, $newQuery, $itemParser);
            }
        );
    }

    /**
     * merchantId is deliberately NOT a caller-supplied argument: the generated
     * `SubscriptionsApi::listSubscriptionCharges(string $merchantId, ...)` is the one list
     * endpoint in this whole surface that needs a merchant id at all (old SDK's
     * `GetSubscriptions`-attached `Subscription::listSubscriptionCharges()` never took one --
     * there was no merchant-scoped route to need it for). Resolved from the Bridge's own JWT
     * claim first (present on both store- and merchant-level tokens, see `Bridge::merchantId()`),
     * falling back to `MerchantsApi::getCurrentMerchant()` only if that claim were ever absent.
     *
     * @param Bridge $bridge
     * @param string $storeId
     * @param string $subscriptionId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listSubscriptionCharges(
        Bridge $bridge,
        string $storeId,
        string $subscriptionId,
        array $query,
        callable $itemParser
    ): Paginated {
        $args = self::buildArgs($query, self::CURSOR_ONLY_ORDER, 'listSubscriptionCharges');
        $merchantId = self::resolveMerchantId($bridge);
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $merchantId, $storeId, $subscriptionId, $args) {
                return $bridge->subscriptions()->listSubscriptionCharges(
                    $merchantId,
                    $storeId,
                    $subscriptionId,
                    ...$args
                );
            },
            $bridge->handlers(),
            "GET /merchants/$merchantId/stores/$storeId/subscriptions/$subscriptionId/charges"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $subscriptionId, $itemParser) {
                return self::listSubscriptionCharges($bridge, $storeId, $subscriptionId, $newQuery, $itemParser);
            }
        );
    }

    /**
     * Mapping decision (old `Mixins\GetScheduledPayments` equivalent): old
     * `ScheduledPayment::listScheduledPayments(...)` only ever took `cursor`/`limit`/
     * `cursorDirection` -- no filters -- and the generated
     * `SubscriptionsApi::listSubscriptionPayments(string $storeId, string $subscriptionId, ...)`
     * has exactly the same three query parameters and nothing else. The mapping is therefore a
     * direct 1:1 rename (`ScheduledPayment` -> `SubscriptionPayment`, `getScheduledPaymentContext`
     * -> `(storeId, subscriptionId)`), not a lossy or backlog-gapped one.
     *
     * @param Bridge $bridge
     * @param string $storeId
     * @param string $subscriptionId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listSubscriptionPayments(
        Bridge $bridge,
        string $storeId,
        string $subscriptionId,
        array $query,
        callable $itemParser
    ): Paginated {
        $args = self::buildArgs($query, self::CURSOR_ONLY_ORDER, 'listSubscriptionPayments');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $storeId, $subscriptionId, $args) {
                return $bridge->subscriptions()->listSubscriptionPayments($storeId, $subscriptionId, ...$args);
            },
            $bridge->handlers(),
            "GET /stores/$storeId/subscriptions/$subscriptionId/payments"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $subscriptionId, $itemParser) {
                return self::listSubscriptionPayments($bridge, $storeId, $subscriptionId, $newQuery, $itemParser);
            }
        );
    }

    /**
     * Backs `Resources\Subscription\ScheduledPayment::listChargesPage()` -- the abstract
     * hook `Mixins\GetCharges` requires, narrowed to `cursor`/`limit`/`cursorDirection` only via
     * that class's own `listCharges()` override (old SDK: `use GetCharges { listCharges as
     * private fullListCharges; }`, same `use`-and-rename trick this repo already ports verbatim).
     * `listChargesForSubscriptionPayment(storeId, subscriptionId, paymentId, ...)` has the exact
     * same three query parameters as every other `CURSOR_ONLY_ORDER` endpoint -- no filters, no
     * lossy mapping.
     *
     * @param Bridge $bridge
     * @param string $storeId
     * @param string $subscriptionId
     * @param string $paymentId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listChargesForSubscriptionPayment(
        Bridge $bridge,
        string $storeId,
        string $subscriptionId,
        string $paymentId,
        array $query,
        callable $itemParser
    ): Paginated {
        $args = self::buildArgs($query, self::CURSOR_ONLY_ORDER, 'listChargesForSubscriptionPayment');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $storeId, $subscriptionId, $paymentId, $args) {
                return $bridge->subscriptions()->listChargesForSubscriptionPayment(
                    $storeId,
                    $subscriptionId,
                    $paymentId,
                    ...$args
                );
            },
            $bridge->handlers(),
            "GET /stores/$storeId/subscriptions/$subscriptionId/payments/$paymentId/charges"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $subscriptionId, $paymentId, $itemParser) {
                return self::listChargesForSubscriptionPayment(
                    $bridge,
                    $storeId,
                    $subscriptionId,
                    $paymentId,
                    $newQuery,
                    $itemParser
                );
            }
        );
    }

    // --- Transaction tokens --------------------------------------------------------------------

    /**
     * @param Bridge $bridge
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listAllTransactionTokens(Bridge $bridge, array $query, callable $itemParser): Paginated
    {
        $args = self::buildArgs($query, self::TOKEN_LIST_ORDER, 'listAllTransactionTokens');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $args) {
                return $bridge->tokens()->listAllTransactionTokens(...$args);
            },
            $bridge->handlers(),
            'GET /tokens'
        );
        return self::wrapPage($decoded, $query, $itemParser, function (array $newQuery) use ($bridge, $itemParser) {
            return self::listAllTransactionTokens($bridge, $newQuery, $itemParser);
        });
    }

    /**
     * @param Bridge $bridge
     * @param string $storeId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listStoreTransactionTokens(
        Bridge $bridge,
        string $storeId,
        array $query,
        callable $itemParser
    ): Paginated {
        $args = self::buildArgs($query, self::TOKEN_LIST_ORDER, 'listStoreTransactionTokens');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $storeId, $args) {
                return $bridge->tokens()->listStoreTransactionTokens($storeId, ...$args);
            },
            $bridge->handlers(),
            "GET /stores/$storeId/tokens"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $itemParser) {
                return self::listStoreTransactionTokens($bridge, $storeId, $newQuery, $itemParser);
            }
        );
    }

    // --- Refunds / Cancels (Charge sub-resources) ---------------------------------------------

    /**
     * @param Bridge $bridge
     * @param string $storeId
     * @param string $chargeId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listRefunds(
        Bridge $bridge,
        string $storeId,
        string $chargeId,
        array $query,
        callable $itemParser
    ): Paginated {
        $args = self::buildArgs($query, self::REFUND_LIST_ORDER, 'listRefunds');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $storeId, $chargeId, $args) {
                return $bridge->refunds()->listRefunds($storeId, $chargeId, ...$args);
            },
            $bridge->handlers(),
            "GET /stores/$storeId/charges/$chargeId/refunds"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $chargeId, $itemParser) {
                return self::listRefunds($bridge, $storeId, $chargeId, $newQuery, $itemParser);
            }
        );
    }

    /**
     * @param Bridge $bridge
     * @param string $storeId
     * @param string $chargeId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listCancels(
        Bridge $bridge,
        string $storeId,
        string $chargeId,
        array $query,
        callable $itemParser
    ): Paginated {
        $args = self::buildArgs($query, self::CURSOR_ONLY_ORDER, 'listCancels');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $storeId, $chargeId, $args) {
                return $bridge->cancels()->listCancels($storeId, $chargeId, ...$args);
            },
            $bridge->handlers(),
            "GET /stores/$storeId/charges/$chargeId/cancels"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $chargeId, $itemParser) {
                return self::listCancels($bridge, $storeId, $chargeId, $newQuery, $itemParser);
            }
        );
    }

    // --- Webhooks (no old-SDK mixin equivalent -- new surface entirely) -----------------------

    /**
     * @param Bridge $bridge
     * @param string $storeId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listWebhooks(Bridge $bridge, string $storeId, array $query, callable $itemParser): Paginated
    {
        $args = self::buildArgs($query, self::WEBHOOK_LIST_ORDER, 'listWebhooks');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $storeId, $args) {
                return $bridge->webhooks()->listWebhooks($storeId, ...$args);
            },
            $bridge->handlers(),
            "GET /stores/$storeId/webhooks"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $itemParser) {
                return self::listWebhooks($bridge, $storeId, $newQuery, $itemParser);
            }
        );
    }

    /**
     * @param Bridge $bridge
     * @param string $storeId
     * @param string $webhookId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listWebhookEvents(
        Bridge $bridge,
        string $storeId,
        string $webhookId,
        array $query,
        callable $itemParser
    ): Paginated {
        $args = self::buildArgs($query, self::CURSOR_ONLY_ORDER, 'listWebhookEvents');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $storeId, $webhookId, $args) {
                return $bridge->webhooks()->listWebhookEvents($storeId, $webhookId, ...$args);
            },
            $bridge->handlers(),
            "GET /stores/$storeId/webhooks/$webhookId/events"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $webhookId, $itemParser) {
                return self::listWebhookEvents($bridge, $storeId, $webhookId, $newQuery, $itemParser);
            }
        );
    }

    // --- Bank transfer ledgers (Charge sub-resource; no old-SDK mixin -- new surface) --------

    /**
     * `ChargesApi::listBankTransferLedgers(string $storeId, string $id)` takes NO query
     * parameters at all -- not even pagination -- despite its response shape (`items`/
     * `has_more`/`total_hits`) looking like every other paginated list. `$query` is therefore
     * expected to be empty; ANY key at all is unmappable here (fail-loud, not silently ignored),
     * including `cursor`/`limit`/`cursor_direction` -- which is the honest answer, since the
     * generated endpoint genuinely has no cursor to page with yet.
     *
     * @param Bridge $bridge
     * @param string $storeId
     * @param string $chargeId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listBankTransferLedgers(
        Bridge $bridge,
        string $storeId,
        string $chargeId,
        array $query,
        callable $itemParser
    ): Paginated {
        $args = self::buildArgs($query, [], 'listBankTransferLedgers');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $storeId, $chargeId, $args) {
                return $bridge->charges()->listBankTransferLedgers($storeId, $chargeId, ...$args);
            },
            $bridge->handlers(),
            "GET /stores/$storeId/charges/$chargeId/bank_transfer_ledgers"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $chargeId, $itemParser) {
                return self::listBankTransferLedgers($bridge, $storeId, $chargeId, $newQuery, $itemParser);
            }
        );
    }

    // --- Transaction history ------------------------------------------------------------------

    /**
     * `GET /transaction_history` -- old SDK's own `listTransactions` already used this path.
     * `Mixins\GetTransactions::listTransactions()`
     * (positional, epoch-millis dates) only ever builds a SUBSET of `TRANSACTION_HISTORY_ORDER`'s
     * keys (`mode`, `from`, `to`, `status`, `type`, `search`, `metadata`, `cursor`, `limit`,
     * `cursor_direction`) -- every other key is a genuine spec-surface gap on the OLD SDK's own
     * mixin, not this dispatcher (see that trait's class doc); `buildArgs()`'s fail-loud contract
     * still applies to any of those extra keys a caller might try anyway (e.g. via
     * `listTransactionsByOptions()`'s raw opts array, which shares this same query-key namespace).
     *
     * @param Bridge $bridge
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listTransactions(Bridge $bridge, array $query, callable $itemParser): Paginated
    {
        $args = self::buildArgs($query, self::TRANSACTION_HISTORY_ORDER, 'listTransactions');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $args) {
                return $bridge->transactionHistory()->listTransactionHistory(...$args);
            },
            $bridge->handlers(),
            'GET /transaction_history'
        );
        return self::wrapPage($decoded, $query, $itemParser, function (array $newQuery) use ($bridge, $itemParser) {
            return self::listTransactions($bridge, $newQuery, $itemParser);
        });
    }

    /**
     * Store-scoped counterpart of `listTransactions()` above -- backs `Resources\Store::
     * listTransactionsPage()`. `GET /stores/{storeId}/transaction_history` (old SDK's own
     * `Store::getTransactionContext()` path).
     *
     * @param Bridge $bridge
     * @param string $storeId
     * @param array $query
     * @param callable $itemParser
     * @return Paginated
     */
    public static function listStoreTransactions(
        Bridge $bridge,
        string $storeId,
        array $query,
        callable $itemParser
    ): Paginated {
        $args = self::buildArgs($query, self::TRANSACTION_HISTORY_ORDER, 'listStoreTransactions');
        $decoded = $bridge->caller()->call(
            function () use ($bridge, $storeId, $args) {
                return $bridge->transactionHistory()->listStoreTransactionHistory($storeId, ...$args);
            },
            $bridge->handlers(),
            "GET /stores/$storeId/transaction_history"
        );
        return self::wrapPage(
            $decoded,
            $query,
            $itemParser,
            function (array $newQuery) use ($bridge, $storeId, $itemParser) {
                return self::listStoreTransactions($bridge, $storeId, $newQuery, $itemParser);
            }
        );
    }

    // --- Shared helpers --------------------------------------------------------------------------

    /**
     * Translates a snake_case `$query` dict into a positional argument list matching `$order`
     * (which must exactly match, in order, the generated controller method's query parameters --
     * see the *_ORDER constants above and their arg-order reflection test). Every key in `$order`
     * not present in `$query` becomes a literal `null` argument (see class doc); every key
     * present in `$query` but absent from `$order` is unmappable and throws.
     *
     * @param array $query
     * @param string[] $order
     * @param string $endpoint
     * @return array
     * @throws UnivapayListDispatchError
     */
    private static function buildArgs(array $query, array $order, string $endpoint): array
    {
        $remaining = $query;
        $args = [];
        foreach ($order as $key) {
            if (array_key_exists($key, $remaining)) {
                $args[] = $remaining[$key];
                unset($remaining[$key]);
            } else {
                $args[] = null;
            }
        }

        if (!empty($remaining)) {
            $unknownKeys = array_keys($remaining);
            throw UnivapayListDispatchError::unmappableKey($unknownKeys[0], $endpoint);
        }

        return $args;
    }

    /**
     * @param array $decoded Raw ApiCaller-decoded response body (assoc array with `items` +
     *        `has_more`, per every `*List` model's shape).
     * @param array $query The query THIS page was fetched with -- stored on the resulting
     *        `Paginated` unchanged (see `Paginated`'s class doc on replaying against the
     *        original query).
     * @param callable $itemParser `function($rawItem): mixed`, applied to each raw item.
     * @param callable $refetch `function(array $newQuery): Paginated`, passed straight through as
     *        the `Paginated`'s fetcher.
     * @return Paginated
     */
    private static function wrapPage(array $decoded, array $query, callable $itemParser, callable $refetch): Paginated
    {
        $rawItems = $decoded['items'] ?? [];
        $items = array_map($itemParser, $rawItems);
        $hasMore = (bool) ($decoded['has_more'] ?? false);
        return new Paginated($items, $hasMore, $query, $refetch);
    }

    /**
     * @param Bridge $bridge
     * @return string
     */
    private static function resolveMerchantId(Bridge $bridge): string
    {
        $merchantId = $bridge->merchantId();
        if (!empty($merchantId)) {
            return $merchantId;
        }

        $decoded = $bridge->caller()->call(
            function () use ($bridge) {
                return $bridge->merchants()->getCurrentMerchant();
            },
            $bridge->handlers(),
            'GET /me'
        );
        return $decoded['id'];
    }
}
