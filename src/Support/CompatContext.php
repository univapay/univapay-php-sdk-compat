<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

/**
 * @internal
 *
 * Lightweight replacement for the old SDK's `Requests\RequestContext`. The old context carried a
 * requester + base URL + path string that resources concatenated by hand
 * (`appendPath()`/`getFullURL()`/`withPath()`) before issuing a raw HTTP call. The new transport
 * engine's generated `Apis\*` controllers already know their own routes -- a resource only ever
 * needs to know WHICH `Bridge` to call through and which store id / resource id to pass as
 * controller arguments, so this holds just those three things instead of reconstructing URLs.
 *
 * Immutable, like the old `RequestContext`'s `with*` methods: `withStoreId()`/`withId()` return a
 * new instance rather than mutating in place.
 */
final class CompatContext
{
    /** @var Bridge */
    private $bridge;

    /** @var string|null */
    public $storeId;

    /** @var string|null */
    public $id;

    /**
     * @param string|null $storeId
     * @param string|null $id
     */
    public function __construct(Bridge $bridge, $storeId = null, $id = null)
    {
        $this->bridge = $bridge;
        $this->storeId = $storeId;
        $this->id = $id;
    }

    public function bridge(): Bridge
    {
        return $this->bridge;
    }

    /**
     * @param string|null $storeId
     */
    public function withStoreId($storeId): self
    {
        return new self($this->bridge, $storeId, $this->id);
    }

    /**
     * @param string|null $id
     */
    public function withId($id): self
    {
        return new self($this->bridge, $this->storeId, $id);
    }
}
