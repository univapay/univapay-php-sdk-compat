<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\DeprecationNotifier;
use Univapay\Compat\Support\NullBridge;
use Univapay\Compat\Support\TypedHydrator;
use Univapay\Compat\Support\TypedResult;

/**
 * Port of the old SDK's `Resources\Resource` base class. Old `fetch()`/`update()` built a
 * `Requests\RequestContext` by hand and issued a raw GET/PATCH through `Utility\RequesterUtils`
 * against it. The new transport engine's generated `Apis\*` controllers already know their own
 * routes, so this instead asks the concrete resource to make its OWN GET/PATCH call through
 * `Support\ApiCaller` via the abstract `fetchCall()`/`updateCall()` methods, which
 * return the ApiCaller-decoded raw body (an assoc array, or `true` for an empty body) rather than
 * a URL-addressed response. `fetch()`/`update()` then hydrate a NEW instance from that body via
 * the class's `Jsonable` schema -- preserving the old SDK's "fetch/update always return a new
 * instance, never mutate `$this`" contract.
 *
 * Every concrete subclass is expected to `use Jsonable;` itself (exactly as in the old SDK, where
 * `Resource` did not use the trait but every resource extending it did) so that `static::
 * getSchema()` below resolves.
 *
 * ## Typed-first hydration
 *
 * `fetchCall()`/`updateCall()` may return either the old contract (`array|true`, the raw decoded
 * body) or a `Support\TypedResult` (raw body + the generated SDK's typed result together, from
 * `Support\ApiCaller::callTyped()`). `fetch()`/`update()` below detect which one they got and
 * route a `TypedResult` through `Support\TypedHydrator::resolve()`; a plain raw body is parsed
 * exactly as before. A resource that hasn't been migrated to `callTyped()` needs no change at all.
 * `callAndHydrate()` always uses `callTyped()`/`resolve()` -- behavior-neutral for any
 * `$targetClass` that has no `hydrateFromTyped()` yet (resolves to the same
 * `getSchema()->parse($rawBody, [$context])` call it always made).
 */
abstract class Resource
{
    /**
     * Native-equivalent label for `fetch()`/`update()` on a resource that has none at all --
     * either it always throws (`Merchant`, `Transfer`, `BankAccount`, `Store::update()`), or the
     * concrete subclass hasn't overridden `nativeFetchEquivalent()`/`nativeUpdateEquivalent()`.
     * See `Support\DeprecationNotifier`'s class doc.
     */
    protected const NO_NATIVE_EQUIVALENT = "no native equivalent (see the compat README's supported surface matrix)";

    /** @var mixed */
    public $id;

    /** @var \Univapay\Compat\Support\CompatContext */
    protected $context;

    /**
     * @param mixed $id
     * @param \Univapay\Compat\Support\CompatContext $context
     */
    protected function __construct($id, $context)
    {
        $this->id = $id;
        $this->context = $context;
    }

    /**
     * Performs this resource's GET request via `Support\ApiCaller` and returns the decoded
     * response body.
     *
     * @return array|true
     */
    abstract protected function fetchCall();

    /**
     * Performs this resource's PATCH/update request with $updates via `Support\ApiCaller` and
     * returns the decoded response body.
     *
     * @param mixed $updates
     * @return array|true
     */
    abstract protected function updateCall($updates);

    /**
     * Convenience accessor so shared hook call sites (`Support\DeprecationNotifier`, the `Pollable`
     * and list-mixin traits) don't need to know whether they're mixed into a `Resource` subclass
     * (`$this->context->bridge()`) or `UnivapayClient` directly (its own `$this->bridge`) --
     * `UnivapayClient` declares its own `getBridge()` returning the same thing its own way.
     * Falls back to `Support\NullBridge` (notices always disabled) when this instance has no
     * `$context` at all -- see that class's own doc for which real resources this applies to.
     * Deliberately no `Bridge` return type: a handful of unit-test fixtures construct a `Resource`
     * subclass with a fake `$context` (no real `Bridge`/HTTP available) and override this method
     * to return a trivial stub instead -- a strict `Bridge` return type would forbid that.
     *
     * @return Bridge|NullBridge
     */
    protected function getBridge()
    {
        if ($this->context === null) {
            return new NullBridge();
        }
        return $this->context->bridge();
    }

    /**
     * @return string Native-SDK equivalent for `fetch()`, e.g. `'ChargesApi::getCharge()'`.
     *         Overridden per concrete subclass that has one; defaults to `NO_NATIVE_EQUIVALENT`.
     */
    protected function nativeFetchEquivalent(): string
    {
        return self::NO_NATIVE_EQUIVALENT;
    }

    /**
     * @return string Native-SDK equivalent for `update()`, e.g. `'ChargesApi::updateCharge()'`.
     *         Overridden per concrete subclass that has one; defaults to `NO_NATIVE_EQUIVALENT`.
     */
    protected function nativeUpdateEquivalent(): string
    {
        return self::NO_NATIVE_EQUIVALENT;
    }

    /**
     * @return static A NEW instance hydrated from a fresh GET, never $this.
     */
    public function fetch()
    {
        $deprecationNotice = DeprecationNotifier::notify(
            $this->getBridge()->deprecationNoticesEnabled(),
            static::class . '::fetch()',
            $this->nativeFetchEquivalent()
        );
        return $this->resolveHydration($this->fetchCall());
    }

    /**
     * @param mixed $updates
     * @return static A NEW instance hydrated from the PATCH response, never $this.
     */
    public function update($updates)
    {
        $deprecationNotice = DeprecationNotifier::notify(
            $this->getBridge()->deprecationNoticesEnabled(),
            static::class . '::update()',
            $this->nativeUpdateEquivalent()
        );
        return $this->resolveHydration($this->updateCall($updates));
    }

    /**
     * @param array|true|TypedResult $result Whatever `fetchCall()`/`updateCall()` returned -- see
     *        class doc "Typed-first hydration".
     * @return static
     */
    protected function resolveHydration($result)
    {
        if ($result instanceof TypedResult) {
            return TypedHydrator::resolve(static::class, $result, $this->context);
        }
        return static::getSchema()->parse($result, [$this->context]);
    }

    /**
     * Generalizes the "build a request -> POST it through Support\ApiCaller -> hydrate the
     * response" flow for calls that create/return a DIFFERENT resource type than `static`, e.g.
     * `TransactionToken::createCharge()` hydrating a `Charge`, not another `TransactionToken`,
     * or `Charge::createRefund()` hydrating a `Refund`. `fetch()`/`update()` above
     * cover the "same resource type, own context" case; this covers the "different resource type,
     * usually a derived context" case, so every concrete resource shares ONE place that wires
     * `Support\ApiCaller` (idempotency key generation, handler cascade, raw-body capture) instead
     * of re-deriving it by hand at every create-a-nested-resource call site.
     *
     * @param string $targetClass FQCN of a class using the `Jsonable` trait (i.e. has a static
     *        `getSchema()`) -- the type of the NEW resource to hydrate, not necessarily `static`.
     * @param callable $controllerFn Same contract as `Support\ApiCaller::call()`'s $controllerFn:
     *        receives the one idempotency key generated for this logical call and reused across
     *        every retry; its return value is ignored (the decoded raw body is always what gets
     *        hydrated -- see `Support\ApiCaller`'s class doc).
     * @param string $urlHint Same contract as `Support\ApiCaller::call()`'s $urlHint.
     * @param \Univapay\Compat\Support\CompatContext|null $hydrationContext Context the new
     *        instance is parsed with; defaults to this resource's own `$context` (same `Bridge`,
     *        same store id) when omitted, mirroring old `RequesterUtils::executePost()`'s use of
     *        the (possibly `withPath()`-derived) calling context to parse the response.
     * @return mixed A NEW $targetClass instance hydrated from the response body.
     */
    protected function callAndHydrate(
        string $targetClass,
        callable $controllerFn,
        string $urlHint,
        $hydrationContext = null
    ) {
        $bridge = $this->context->bridge();
        $result = $bridge->caller()->callTyped($controllerFn, $bridge->handlers(), $urlHint);
        return TypedHydrator::resolve(
            $targetClass,
            $result,
            $hydrationContext !== null ? $hydrationContext : $this->context
        );
    }
}
