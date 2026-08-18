<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use DateTime;
use UnivaPay\Models\CreateCustomerIdRequest;
use UnivaPay\Models\Store as GeneratedStore;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\Configuration\Configuration;
use Univapay\Compat\Resources\Mixins\GetCharges;
use Univapay\Compat\Resources\Mixins\GetSubscriptions;
use Univapay\Compat\Resources\Mixins\GetTransactions;
use Univapay\Compat\Support\ListDispatcher;
use Univapay\Compat\Support\TypedHydrator;
use Univapay\Compat\Support\TypedResult;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\Store` (namespace lines + transport plumbing only -- public
 * props are otherwise verbatim). Property order (name, createdOn, configuration -- `id` comes
 * first for free via the inherited `Resource::$id`) already matches the old constructor.
 *
 * ## Transport wiring
 *
 * Old `getCharge($chargeId)`/`getSubscription($subscriptionId)` built a context via
 * `$this->getIdContext()->appendPath([...])` -- i.e. always relative to THIS store's own id. Here
 * that's just `$this->id` passed as the generated controllers' `$storeId` argument directly; no
 * context-path bookkeeping needed. Same shape as every other resource's `fetchCall()`/
 * `updateCall()` wiring (pull the controller off `$this->context->bridge()`, run a closure through
 * `Support\ApiCaller`).
 *
 * Old `Store` never had a real update()/PATCH endpoint despite inheriting one for free from
 * `Resource` -- nothing in the old SDK's public surface, docs, or tests ever called
 * `$store->update(...)`, and there is no generated `Apis\StoresApi::updateStore()` to route it
 * through even if a caller tried. `updateCall()` therefore throws unconditionally.
 *
 * `getCustomerId()` (`POST /stores/{id}/create_customer_id`, used internally by
 * `UnivapayClient::createToken()`'s RECURRING + local-customer-id branch) routes through
 * `StoresApi::createCustomerId()`. `Support\RequestModelFactory::tokenCreate()`'s own
 * (coarser, type-agnostic, PERMANENT) `$localCustomerId` guard is never reached from the client
 * for this reason -- see `UnivapayClient::createToken()`'s class doc.
 *
 * `GetTransactions` mixin: fully ported (matches `Mixins\GetTransactions`'s own class doc) --
 * `listTransactionsPage()` below delegates to `Support\ListDispatcher::listStoreTransactions()`,
 * the real `GET /stores/{storeId}/transaction_history`, exactly like the client's own merchant-wide
 * `listTransactions()`.
 */
class Store extends Resource
{
    use Jsonable;
    use GetCharges, GetSubscriptions, GetTransactions {
        GetCharges::validate insteadof GetSubscriptions, GetTransactions;
    }

    public $name;
    public $createdOn;
    public $configuration;

    /**
     * @param mixed $id
     * @param mixed $name
     * @param mixed $configuration
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $name,
        DateTime $createdOn,
        $configuration,
        $context = null
    ) {
        parent::__construct($id, $context);
        $this->name = $name;
        $this->createdOn = $createdOn;
        $this->configuration = $configuration;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('configuration', false, Configuration::getSchema()->getParser())
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'));
    }

    /**
     * Typed-first hydration entry point for `Support\TypedHydrator`. Clean 1:1 match against the
     * generated SDK's `UnivaPay\Models\Store` -- `configuration` is dispatched to `Configuration::
     * hydrateFromTyped()` (see its own doc for the nested `Configuration\*` tree audit). Unlike
     * `Merchant`, `configuration` is OPTIONAL here (required=false in this class's own schema):
     * missing/unmappable resolves to null instead of declining the whole `Store`.
     *
     * @param mixed $typed
     * @param array $body
     * @param \Univapay\Compat\Support\CompatContext|null $context
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body, $context)
    {
        if (!($typed instanceof GeneratedStore)) {
            return null;
        }
        $createdOn = $typed->getCreatedOn();
        if ($createdOn === null) {
            return null;
        }

        $configurationTyped = $typed->getConfiguration();
        $configuration = null;
        if ($configurationTyped !== null) {
            $configurationBody = isset($body['configuration']) && is_array($body['configuration'])
                ? $body['configuration']
                : [];
            $configuration = Configuration::hydrateFromTyped($configurationTyped, $configurationBody);
        }

        return new self($typed->getId(), $typed->getName(), $createdOn, $configuration, $context);
    }

    // --- Resource wiring (fetch/update) ----------------------------------------------------------

    protected function fetchCall()
    {
        $bridge = $this->context->bridge();
        $stores = $bridge->stores();
        return $bridge->caller()->callTyped(
            function () use ($stores) {
                return $stores->getStore($this->id);
            },
            $bridge->handlers(),
            "GET /stores/{$this->id}"
        );
    }

    /**
     * @see class doc -- no store update endpoint was ever exposed by the old SDK.
     */
    protected function updateCall($updates)
    {
        throw new UnivapayUnsupportedFeatureError('Store::update() (no store update endpoint exists)');
    }

    public function getCharge($chargeId)
    {
        $bridge = $this->context->bridge();
        $charges = $bridge->charges();
        $result = $bridge->caller()->callTyped(
            function () use ($charges, $chargeId) {
                return $charges->getCharge($this->id, $chargeId);
            },
            $bridge->handlers(),
            "GET /stores/{$this->id}/charges/$chargeId"
        );
        return TypedHydrator::resolve(Charge::class, $result, $this->context);
    }

    public function getSubscription($subscriptionId)
    {
        $bridge = $this->context->bridge();
        $subscriptions = $bridge->subscriptions();
        $body = $bridge->caller()->call(
            function () use ($subscriptions, $subscriptionId) {
                return $subscriptions->getSubscription($this->id, $subscriptionId);
            },
            $bridge->handlers(),
            "GET /stores/{$this->id}/subscriptions/$subscriptionId"
        );
        return Subscription::getSchema()->parse($body, [$this->context]);
    }

    /**
     * `POST /stores/{id}/create_customer_id`. Deterministic, no side effects
     * (calling again with the same `$localCustomerId` for the same store always returns the same
     * UUID -- per the generated `StoresApi::createCustomerId()`'s own doc), so this does not need
     * (and does not get) its own idempotency-key concern beyond `Support\ApiCaller`'s usual one.
     *
     * @param mixed $localCustomerId
     * @return string The deterministic per-store customer UUID.
     */
    public function getCustomerId($localCustomerId)
    {
        $request = new CreateCustomerIdRequest((string) $localCustomerId);
        $bridge = $this->context->bridge();
        $stores = $bridge->stores();
        $body = $bridge->caller()->call(
            function ($idempotencyKey) use ($stores, $request) {
                return $stores->createCustomerId($this->id, $request);
            },
            $bridge->handlers(),
            "POST /stores/{$this->id}/create_customer_id"
        );
        return $body['customer_id'];
    }

    // --- GetCharges/GetSubscriptions/GetTransactions mixin hooks ---------------------------------

    protected function listChargesPage(array $query)
    {
        $bridge = $this->context->bridge();
        return ListDispatcher::listStoreCharges(
            $bridge,
            $this->id,
            $query,
            function ($raw, $typed = null) {
                return TypedHydrator::resolve(Charge::class, new TypedResult($raw, $typed, false), $this->context);
            }
        );
    }

    protected function listSubscriptionsPage(array $query)
    {
        $bridge = $this->context->bridge();
        return ListDispatcher::listStoreSubscriptions(
            $bridge,
            $this->id,
            $query,
            function ($raw) {
                return Subscription::getSchema()->parse($raw, [$this->context]);
            }
        );
    }

    /**
     * @see Mixins\GetTransactions's class doc and Support\ListDispatcher::listStoreTransactions()
     */
    protected function listTransactionsPage(array $query)
    {
        $bridge = $this->context->bridge();
        return ListDispatcher::listStoreTransactions(
            $bridge,
            $this->id,
            $query,
            function ($raw) {
                return Transaction::getSchema()->parse($raw, [$this->context]);
            }
        );
    }
}
