<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

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
 */
abstract class Resource
{
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
     * @return static A NEW instance hydrated from a fresh GET, never $this.
     */
    public function fetch()
    {
        $body = $this->fetchCall();
        return static::getSchema()->parse($body, [$this->context]);
    }

    /**
     * @param mixed $updates
     * @return static A NEW instance hydrated from the PATCH response, never $this.
     */
    public function update($updates)
    {
        $body = $this->updateCall($updates);
        return static::getSchema()->parse($body, [$this->context]);
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
        $body = $bridge->caller()->call($controllerFn, $bridge->handlers(), $urlHint);
        return $targetClass::getSchema()->parse(
            $body,
            [$hydrationContext !== null ? $hydrationContext : $this->context]
        );
    }
}
