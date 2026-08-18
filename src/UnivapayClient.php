<?php

declare(strict_types=1);

namespace Univapay\Compat;

use DateTime;
use Money\Money;
use OutOfRangeException;
use Throwable;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\Period;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Enums\WebhookEvent;
use Univapay\Compat\Errors\UnivapayInvalidWebhookData;
use Univapay\Compat\Errors\UnivapayUnknownWebhookEvent;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Requests\Handlers\RequestHandler;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Cancel;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Resources\CheckoutInfo;
use Univapay\Compat\Resources\Merchant;
use Univapay\Compat\Resources\Mixins\GetBankAccounts;
use Univapay\Compat\Resources\Mixins\GetCharges;
use Univapay\Compat\Resources\Mixins\GetStores;
use Univapay\Compat\Resources\Mixins\GetSubscriptions;
use Univapay\Compat\Resources\Mixins\GetTransactions;
use Univapay\Compat\Resources\Mixins\GetTransactionTokens;
use Univapay\Compat\Resources\Mixins\GetTransfers;
use Univapay\Compat\Resources\PaymentMethod\PaymentMethod;
use Univapay\Compat\Resources\PaymentThreeDS;
use Univapay\Compat\Resources\Redirect;
use Univapay\Compat\Resources\Refund;
use Univapay\Compat\Resources\SimpleList;
use Univapay\Compat\Resources\Store;
use Univapay\Compat\Resources\Subscription;
use Univapay\Compat\Resources\Subscription\InstallmentPlan;
use Univapay\Compat\Resources\Subscription\ScheduledPayment;
use Univapay\Compat\Resources\Subscription\ScheduleSettings;
use Univapay\Compat\Resources\Subscription\SubscriptionPlan;
use Univapay\Compat\Resources\Transaction;
use Univapay\Compat\Resources\TransactionToken;
use Univapay\Compat\Resources\Transfer;
use Univapay\Compat\Resources\WebhookPayload;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\CompatContext;
use Univapay\Compat\Support\ListDispatcher;
use Univapay\Compat\Support\RequestModelFactory;
use Univapay\Compat\Support\TypedHydrator;

/**
 * Port of the old SDK's `UnivapayClient` -- the facade every consumer constructs directly
 * (`new UnivapayClient(AppJWT::createToken(...))`). Old `UnivapayClient` held its own
 * `$appToken`/`$clientOptions`/`Requests\HttpRequester`; here all of that (JWT, options, handler
 * cascade, the generated transport engine client, `Support\ApiCaller`) lives behind ONE
 * `Support\Bridge` this class owns by composition -- see `Bridge`'s own class doc for why the
 * store-app-token guard (`requireStoreId()`) and the memoized per-API controller accessors live
 * there instead of being re-derived here.
 *
 * ## Two-step create flows (`createCharge()`/`createSubscription()`)
 *
 * Old `UnivapayClient::createCharge()`/`createSubscription()` were themselves already just:
 * `$this->getTransactionToken($transactionTokenId)->createCharge(...)` /
 * `->createSubscription(...)` -- i.e. a GET of the token followed by delegating to the token's OWN
 * create method. This is preserved EXACTLY (not reimplemented against the generated
 * `ChargesApi`/`SubscriptionsApi` directly), which gives three old-SDK behaviors for free, without
 * duplicating any of them here:
 *
 * 1. **The preflight GET.** `getTransactionToken()` issues a real `GET /stores/{storeId}/tokens/
 *    {id}` through `Support\ApiCaller` before any charge/subscription creation is attempted.
 * 2. **404 timing.** If the token id does not exist, `Support\ExceptionMapper` maps the GET's 404
 *    into `Errors\UnivapayNotFoundError` and it propagates BEFORE any `POST` is ever issued --
 *    matching old `RequesterUtils::executeGet()`'s error timing exactly.
 * 3. **All of `TransactionToken`'s create-time guards** (`validateCreateCharge()`/`validateCVV()`/
 *    `validateCapture()` for charges; the ONE_TIME rejection, period-XOR-cyclical-period,
 *    positive-amount, initial-amount-same-currency, and preserve-end-of-month-monthly-only checks
 *    for subscriptions) fire from INSIDE `TransactionToken::createCharge()`/`createSubscription()`,
 *    exactly where the old client's own delegation put them. There is nothing left for the
 *    client-level methods here to validate themselves.
 *
 * `Support\Bridge::requireStoreId()` (the `REQUIRES_STORE_APP_TOKEN` guard) fires even earlier,
 * inside `getTransactionToken()` itself, before the preflight GET is issued -- pre-HTTP, matching
 * old `getStoreBasedContext()`'s guard position exactly.
 *
 * ## createToken(): the RECURRING + local-customer-id branch
 *
 * Old `createToken()` only calls `Store::getCustomerId()` (to inject a `gopay-customer-id`
 * metadata key) when BOTH `$localCustomerId` is set AND `$payment->type === TokenType::
 * RECURRING()` -- for every other payment type, a non-null `$localCustomerId` is silently ignored
 * and token creation proceeds normally. That exact gate is reproduced here, deliberately NOT by
 * forwarding `$localCustomerId` into `Support\RequestModelFactory::tokenCreate()` (whose OWN,
 * coarser guard throws `UnivapayUnsupportedFeatureError` for ANY non-null `$localCustomerId`,
 * regardless of payment type -- see that method's class doc): doing so would incorrectly break
 * `createToken($oneTimeCardPayment, 'some-id')` calls that old code accepted (RECURRING check
 * fails, so old code just proceeds -- no store round-trip). The RECURRING branch instead calls
 * `getStore($storeId)->getCustomerId($localCustomerId)`, which makes a real
 * `POST /stores/{id}/create_customer_id` call (see `Resources\Store::getCustomerId()`).
 *
 * ## Mixins
 *
 * `GetBankAccounts`/`GetCharges`/`GetStores`/`GetSubscriptions`/`GetTransactions`/
 * `GetTransactionTokens`/`GetTransfers` -- same set the old client attached, same `GetCharges::
 * validate insteadof ...` conflict-resolution shape as `Charge`/`Subscription`/`Store`:
 * `GetBankAccounts`/`GetTransfers` are deliberately excluded from the `insteadof` list because the
 * compat-rewritten `GetBankAccounts` (unconditional-throw-only -- bank accounts permanently
 * unsupported) and `GetTransfers` (unconditional-throw-only) no longer `use` `OptionsValidator` at
 * all, so neither declares a `validate()` method to conflict with.
 */
class UnivapayClient
{
    use GetBankAccounts, GetCharges, GetStores, GetSubscriptions, GetTransactions, GetTransactionTokens, GetTransfers {
        GetCharges::validate insteadof GetStores, GetSubscriptions, GetTransactions, GetTransactionTokens;
    }

    /** @var Bridge */
    private $bridge;

    public function __construct(AppJWT $appToken, ?UnivapayClientOptions $clientOptions = null)
    {
        $this->bridge = new Bridge($appToken, $clientOptions);
    }

    /**
     * Escape hatch for migrating call sites off the compat layer onto the native, generated SDK.
     *
     * Compat cannot be swapped for the native, generated SDK in one pass (`Money` value objects,
     * public-property resources, and enum identity all differ). Instead, migration happens
     * file-by-file: call sites that have been rewritten
     * against the native SDK call `native()` and use its typed API controllers directly; call
     * sites that have not yet migrated keep calling this compat facade as before. Both paths run
     * through the exact SAME engine -- `native()` returns this client's own `Support\Bridge`-owned
     * `UnivaPay\UnivapayClientSdkClient` instance (same auth, baseUrl, timeout, HTTP callback),
     * never a second, independently-configured client -- so there is no drift between the two APIs
     * during the migration window, and `addHandlers()`/`setHandlers()` calls made through the
     * compat side keep applying to compat-side calls made afterwards regardless of how much of the
     * codebase has moved to `native()`.
     *
     * @return \UnivaPay\UnivapayClientSdkClient
     */
    public function native(): \UnivaPay\UnivapayClientSdkClient
    {
        return $this->bridge->client();
    }

    /**
     * @see Support\Bridge::addHandlers()
     */
    public function addHandlers(RequestHandler ...$handlers): void
    {
        $this->bridge->addHandlers(...$handlers);
    }

    /**
     * @see Support\Bridge::setHandlers()
     */
    public function setHandlers(RequestHandler ...$handlers): void
    {
        $this->bridge->setHandlers(...$handlers);
    }

    public function getMe()
    {
        $merchants = $this->bridge->merchants();
        $body = $this->bridge->caller()->call(
            function () use ($merchants) {
                return $merchants->getCurrentMerchant();
            },
            $this->bridge->handlers(),
            'GET /me'
        );
        return Merchant::getSchema()->parse($body, [new CompatContext($this->bridge)]);
    }

    /**
     * `GET /checkout_info`. `requireStoreId()` still fires first, matching old
     * `getStoreBasedContext()`'s guard position -- the generated `CheckoutApi::getCheckoutInfo()`
     * itself takes no parameters at all (resolved entirely from the bearer credential), but the
     * store-token gate is a real old-SDK behavior independent of the endpoint's own shape.
     */
    public function getCheckoutInfo()
    {
        $this->bridge->requireStoreId();
        $checkout = $this->bridge->checkout();
        $body = $this->bridge->caller()->call(
            function () use ($checkout) {
                return $checkout->getCheckoutInfo();
            },
            $this->bridge->handlers(),
            'GET /checkout_info'
        );
        return CheckoutInfo::getSchema()->parse($body);
    }

    public function getStore($id)
    {
        $stores = $this->bridge->stores();
        $body = $this->bridge->caller()->call(
            function () use ($stores, $id) {
                return $stores->getStore($id);
            },
            $this->bridge->handlers(),
            "GET /stores/$id"
        );
        return Store::getSchema()->parse($body, [new CompatContext($this->bridge, $id)]);
    }

    /**
     * UNSUPPORTED, permanently -- see `Resources\BankAccount`'s class doc (no Bank Accounts API
     * exists in the new transport engine).
     *
     * @param mixed $id
     * @throws UnivapayUnsupportedFeatureError Always.
     */
    public function getBankAccount($id)
    {
        throw new UnivapayUnsupportedFeatureError('UnivapayClient::getBankAccount() (Bank Accounts)');
    }

    /**
     * @param mixed $localCustomerId
     * @see class doc "createToken(): the RECURRING + local-customer-id branch"
     */
    public function createToken(PaymentMethod $payment, $localCustomerId = null)
    {
        $storeId = $this->bridge->requireStoreId();

        if (isset($localCustomerId) && $payment->type === TokenType::RECURRING()) {
            $customerId = $this->getCustomerId($localCustomerId, $storeId);
            if (!isset($payment->metadata)) {
                $payment->metadata = [];
            }
            $payment->metadata += ['gopay-customer-id' => $customerId];
        }

        // $localCustomerId is deliberately never forwarded here -- see class doc.
        $request = RequestModelFactory::tokenCreate($payment);
        $tokens = $this->bridge->tokens();
        $result = $this->bridge->caller()->callTyped(
            function ($idempotencyKey) use ($tokens, $request) {
                return $tokens->createTransactionToken($request, $idempotencyKey);
            },
            $this->bridge->handlers(),
            'POST /tokens'
        );
        return TypedHydrator::resolve(TransactionToken::class, $result, new CompatContext($this->bridge, $storeId));
    }

    /**
     * @param mixed $transactionTokenId
     */
    public function getTransactionToken($transactionTokenId)
    {
        $storeId = $this->bridge->requireStoreId();
        $tokens = $this->bridge->tokens();
        $result = $this->bridge->caller()->callTyped(
            function () use ($tokens, $storeId, $transactionTokenId) {
                return $tokens->getTransactionToken($storeId, $transactionTokenId);
            },
            $this->bridge->handlers(),
            "GET /stores/$storeId/tokens/$transactionTokenId"
        );
        return TypedHydrator::resolve(TransactionToken::class, $result, new CompatContext($this->bridge, $storeId));
    }

    /**
     * @param mixed $transactionTokenId
     * @param mixed $capture
     * @param mixed $onlyDirectCurrency
     * @see class doc "Two-step create flows"
     */
    public function createCharge(
        $transactionTokenId,
        Money $money,
        $capture = null,
        ?DateTime $captureAt = null,
        ?array $metadata = null,
        $onlyDirectCurrency = null,
        ?Redirect $redirect = null,
        ?PaymentThreeDS $paymentThreeDS = null
    ) {
        return $this
            ->getTransactionToken($transactionTokenId)
            ->createCharge(
                $money,
                $capture,
                $captureAt,
                $metadata,
                $onlyDirectCurrency,
                $redirect,
                $paymentThreeDS
            );
    }

    /**
     * @param mixed $storeId
     * @param mixed $chargeId
     */
    public function getCharge($storeId, $chargeId)
    {
        $charges = $this->bridge->charges();
        $result = $this->bridge->caller()->callTyped(
            function () use ($charges, $storeId, $chargeId) {
                return $charges->getCharge($storeId, $chargeId);
            },
            $this->bridge->handlers(),
            "GET /stores/$storeId/charges/$chargeId"
        );
        return TypedHydrator::resolve(Charge::class, $result, new CompatContext($this->bridge, $storeId));
    }

    /**
     * @param mixed $storeId
     * @param mixed $subscriptionId
     */
    public function getLatestChargeForSubscription($storeId, $subscriptionId)
    {
        $subscriptions = $this->bridge->subscriptions();
        $result = $this->bridge->caller()->callTyped(
            function () use ($subscriptions, $storeId, $subscriptionId) {
                return $subscriptions->getSubscriptionLatestCharge($storeId, $subscriptionId);
            },
            $this->bridge->handlers(),
            "GET /stores/$storeId/subscriptions/$subscriptionId/charges/latest"
        );
        return TypedHydrator::resolve(Charge::class, $result, new CompatContext($this->bridge, $storeId));
    }

    /**
     * @param mixed $transactionTokenId
     * @see class doc "Two-step create flows"
     */
    public function createSubscription(
        $transactionTokenId,
        Money $money,
        Period $period,
        ?Money $initialAmount = null,
        ?ScheduleSettings $scheduleSettings = null,
        ?SubscriptionPlan $subscriptionPlan = null,
        ?InstallmentPlan $installmentPlan = null,
        ?array $metadata = null,
        ?PaymentThreeDS $paymentThreeDS = null
    ) {
        return $this
            ->getTransactionToken($transactionTokenId)
            ->createSubscription(
                $money,
                $period,
                $initialAmount,
                $scheduleSettings,
                $subscriptionPlan,
                $installmentPlan,
                $metadata,
                null, // only direct currency
                null, // first charge authorization only
                null, // first charge capture after
                null, // cyclical period
                $paymentThreeDS
            );
    }

    /**
     * @param mixed $storeId
     * @param mixed $subscriptionId
     */
    public function getSubscription($storeId, $subscriptionId)
    {
        $subscriptions = $this->bridge->subscriptions();
        $body = $this->bridge->caller()->call(
            function () use ($subscriptions, $storeId, $subscriptionId) {
                return $subscriptions->getSubscription($storeId, $subscriptionId);
            },
            $this->bridge->handlers(),
            "GET /stores/$storeId/subscriptions/$subscriptionId"
        );
        return Subscription::getSchema()->parse($body, [new CompatContext($this->bridge, $storeId)]);
    }

    /**
     * `POST /stores/{storeId}/subscriptions/simulate_plan`. `requireStoreId()` fires first (same
     * store-token gate as `createToken()`/`getTransactionToken()`/`getCheckoutInfo()`), then routes
     * through the STORE-scoped simulation endpoint (rather than the merchant-wide
     * `POST /subscriptions/simulate_plan`, which this client-level method never had a JWT-only
     * reason to prefer over threading the already-resolved `$storeId` explicitly, matching every
     * other per-store method in this class). Response is a BARE JSON ARRAY (no `{items, has_more}`
     * envelope) of scheduled payments -- hydrated into a `Resources\SimpleList` of
     * `Resources\Subscription\ScheduledPayment`, old-SDK parity (`SimpleList`, not `Paginated`: no
     * cursor/`has_more` envelope exists for this endpoint at all).
     */
    public function createSubscriptionSimulation(
        PaymentType $paymentType,
        Money $amount,
        Period $period,
        ?Money $initialAmount = null,
        ?ScheduleSettings $scheduleSettings = null,
        ?SubscriptionPlan $subscriptionPlan = null,
        ?InstallmentPlan $installmentPlan = null
    ) {
        $storeId = $this->bridge->requireStoreId();
        $request = RequestModelFactory::subscriptionSimulationCreate(
            $paymentType,
            $amount,
            $period,
            $initialAmount,
            $scheduleSettings,
            $subscriptionPlan,
            $installmentPlan
        );
        $subscriptions = $this->bridge->subscriptions();
        $body = $this->bridge->caller()->call(
            function ($idempotencyKey) use ($subscriptions, $storeId, $request) {
                return $subscriptions->simulateStoreSubscriptionPlan($storeId, $idempotencyKey, $request);
            },
            $this->bridge->handlers(),
            "POST /stores/$storeId/subscriptions/simulate_plan"
        );
        $context = new CompatContext($this->bridge, $storeId);
        $items = array_map(function ($raw) use ($context) {
            return ScheduledPayment::getSchema()->parse($raw, [$context]);
        }, is_array($body) ? $body : []);
        return new SimpleList($items);
    }

    /**
     * UNSUPPORTED, permanently -- see `Resources\Transfer`'s class doc (final unsupported list,
     * no Transfers API in the new transport engine at all).
     *
     * @param mixed $id
     * @throws UnivapayUnsupportedFeatureError Always.
     */
    public function getTransfer($id)
    {
        throw new UnivapayUnsupportedFeatureError('UnivapayClient::getTransfer() (Transfers)');
    }

    /**
     * Port of the old SDK's `UnivapayClient::parseWebhookData()` -- dispatches on the wire
     * `event` string (`WebhookEvent::fromValue()`) to the resource type that event's `data`
     * payload hydrates as, and wraps the result in a `WebhookPayload`. No signature verification,
     * matching upstream exactly (that was never part of the old SDK's contract either).
     *
     * ## Corner-case semantics -- reproduced VERBATIM, not "fixed"
     *
     * **Merchant-JWT events.** Old `getTransactionTokenContext()` (TOKEN_* events) and
     * `getStoreBasedContext()` (REFUND_FINISHED/CANCEL_FINISHED) both required a `StoreAppJWT` --
     * calling them with a merchant-level app token threw `UnivapaySDKError` (a `UnivapayError`,
     * hence an `Exception`) WHILE the parser was still being selected, inside the `try` block,
     * which old code's `catch (Exception $exception) { throw new UnivapayInvalidWebhookData($data);
     * }` then swallowed into `UnivapayInvalidWebhookData` -- i.e. a merchant-JWT client receiving a
     * TOKEN_-prefixed / REFUND_FINISHED / CANCEL_FINISHED webhook gets `UnivapayInvalidWebhookData`,
     * not the `UnivapaySDKError`/`REQUIRES_STORE_APP_TOKEN` one might expect. `Bridge::
     * requireStoreId()` below reproduces that same guard at the same point for the same three
     * event groups (CHARGE-prefixed / SUBSCRIPTION-prefixed / TRANSFER-prefixed events use
     * `getContext()`/`getTransferContext()` in old code -- NOT store-scoped -- so they hydrate
     * regardless of JWT type; that asymmetry is preserved exactly).
     *
     * **Unmapped-but-valid events (`CUSTOMS_DECLARATION_FINISHED`).** `WebhookEvent` has a
     * `CUSTOMS_DECLARATION_FINISHED` case, but neither old code nor this switch has an arm for it
     * -- `$parser` is left `null`, and `$parser($data['data'])` is then a call on `null`. In real
     * PHP 7+ this throws `\Error` ("Value of type null is not callable"), NOT `\Exception` --
     * `\Error` and `\Exception` are siblings under `\Throwable`, so old code's literal
     * `catch (Exception $exception)` would NOT actually catch this in practice. Unmapped-but-valid
     * events must still surface as `UnivapayInvalidWebhookData` (verified directly: see the webhook
     * fixture tests, and `php -r '(null)();'` under `catch (Exception)` vs `catch (Throwable)`).
     * This method therefore catches `Throwable` here (one level broader than old code's `Exception`)
     * specifically to
     * reproduce that documented outcome for real -- not to "fix" the underlying gap (there is
     * still no parser for CUSTOMS_DECLARATION_FINISHED; the exception TYPE the caller observes is
     * what is pinned). `OutOfRangeException` (truly unrecognized event STRINGS, e.g. a typo'd or
     * future-unknown `event` value that `WebhookEvent::fromValue()` itself rejects) is caught
     * first and separately, exactly as upstream, so it still maps to
     * `UnivapayUnknownWebhookEvent`, never to `UnivapayInvalidWebhookData`.
     *
     * **Transfer events.** `TRANSFER_CREATED`/`TRANSFER_UPDATED`/`TRANSFER_FINALIZED` hydrate a
     * real `Resources\Transfer` regardless of JWT type or the fact that `Transfer` itself is
     * UNSUPPORTED for direct API access (see its class doc) -- the webhook data keeps arriving over
     * this channel independent of whether the REST Transfers API exists in this transport engine.
     * Any subsequent call on the hydrated `Transfer` (`fetch()`, `update()`, `listLedgers()`,
     * `listStatusChanges()`) still throws `UnivapayUnsupportedFeatureError` -- this parses the
     * payload, it does not make the resource itself supported.
     *
     * @param array $data
     * @return WebhookPayload
     * @throws UnivapayUnknownWebhookEvent If `$data['event']` is not a recognized `WebhookEvent`
     *         value.
     * @throws UnivapayInvalidWebhookData On any other failure while selecting a parser or
     *         hydrating `$data['data']` -- including the merchant-JWT and unmapped-event corners
     *         documented above.
     */
    public function parseWebhookData(array $data)
    {
        try {
            $event = WebhookEvent::fromValue($data['event']);
            $parser = null;
            switch ($event) {
                case WebhookEvent::TOKEN_CREATED():
                case WebhookEvent::TOKEN_UPDATED():
                case WebhookEvent::TOKEN_CVV_AUTH_UPDATED():
                case WebhookEvent::RECURRING_TOKEN_DELETED():
                    // Old getTransactionTokenContext() = getStoreBasedContext()->withPath('tokens')
                    // -- requireStoreId() reproduces its store-app-token guard (see method doc's
                    // "merchant-JWT events" corner).
                    $this->bridge->requireStoreId();
                    $parser = TransactionToken::getContextParser(new CompatContext($this->bridge));
                    break;

                case WebhookEvent::CHARGE_UPDATED():
                case WebhookEvent::CHARGE_FINISHED():
                    $parser = Charge::getContextParser(new CompatContext($this->bridge));
                    break;

                case WebhookEvent::SUBSCRIPTION_PAYMENT():
                case WebhookEvent::SUBSCRIPTION_COMPLETED():
                case WebhookEvent::SUBSCRIPTION_FAILURE():
                case WebhookEvent::SUBSCRIPTION_CANCELED():
                case WebhookEvent::SUBSCRIPTION_SUSPENDED():
                    $parser = Subscription::getContextParser(new CompatContext($this->bridge));
                    break;

                case WebhookEvent::REFUND_FINISHED():
                    // Old getStoreBasedContext() directly -- same guard as the token branch above.
                    $this->bridge->requireStoreId();
                    $parser = Refund::getContextParser(new CompatContext($this->bridge));
                    break;

                case WebhookEvent::CANCEL_FINISHED():
                    $this->bridge->requireStoreId();
                    $parser = Cancel::getContextParser(new CompatContext($this->bridge));
                    break;

                case WebhookEvent::TRANSFER_CREATED():
                case WebhookEvent::TRANSFER_UPDATED():
                case WebhookEvent::TRANSFER_FINALIZED():
                    $parser = Transfer::getContextParser(new CompatContext($this->bridge));
                    break;
            }
            return new WebhookPayload($event, $parser($data['data']));
        } catch (OutOfRangeException $exception) {
            throw new UnivapayUnknownWebhookEvent($data['event']);
        } catch (Throwable $throwable) {
            // Deliberately Throwable, not Exception -- see method doc's "Unmapped-but-valid
            // events" corner for why old code's literal catch(Exception) would not actually catch
            // the null-parser \Error case in real PHP, and why this class widens the catch to
            // reproduce the UnivapayInvalidWebhookData outcome anyway.
            throw new UnivapayInvalidWebhookData($data);
        }
    }

    /**
     * @param mixed $localCustomerId
     */
    protected function getCustomerId($localCustomerId, $storeId)
    {
        return $this->getStore($storeId)->getCustomerId($localCustomerId);
    }

    // --- GetCharges/GetStores/GetSubscriptions/GetTransactions/GetTransactionTokens mixin hooks
    //     (GetBankAccounts/GetTransfers both throw unconditionally, no hook needed) -------------

    protected function listChargesPage(array $query)
    {
        return ListDispatcher::listAllCharges(
            $this->bridge,
            $query,
            function ($raw) {
                return Charge::getSchema()->parse($raw, [new CompatContext($this->bridge)]);
            }
        );
    }

    protected function listStoresPage(array $query)
    {
        return ListDispatcher::listStores(
            $this->bridge,
            $query,
            function ($raw) {
                return Store::getSchema()->parse($raw, [new CompatContext($this->bridge)]);
            }
        );
    }

    protected function listSubscriptionsPage(array $query)
    {
        return ListDispatcher::listAllSubscriptions(
            $this->bridge,
            $query,
            function ($raw) {
                return Subscription::getSchema()->parse($raw, [new CompatContext($this->bridge)]);
            }
        );
    }

    protected function listTransactionsPage(array $query)
    {
        return ListDispatcher::listTransactions(
            $this->bridge,
            $query,
            function ($raw) {
                return Transaction::getSchema()->parse($raw, [new CompatContext($this->bridge)]);
            }
        );
    }

    protected function listTransactionTokensPage(array $query)
    {
        return ListDispatcher::listAllTransactionTokens(
            $this->bridge,
            $query,
            function ($raw) {
                return TransactionToken::getSchema()->parse($raw, [new CompatContext($this->bridge)]);
            }
        );
    }
}
