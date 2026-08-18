<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

/**
 * Port of the old SDK's `Resources\SimpleList` -- a non-paginated wrapper for endpoints that
 * answer with a plain list and no cursor/`has_more` envelope at all (old usage: subscription plan
 * simulation). Old `fromResponse()` mapped a raw array response through `$jsonableClass::
 * getContextParser($context)` itself; here (as with `Paginated`, see its class doc) hydration
 * happens in the caller via the ported `Jsonable` schema parser, so this class only ever holds
 * already-parsed items.
 *
 * `$items` stays PUBLIC, matching old-SDK parity exactly.
 */
final class SimpleList
{
    /** @var array */
    public $items;

    /**
     * @param array $items Already-hydrated items.
     */
    public function __construct(array $items)
    {
        $this->items = $items;
    }
}
