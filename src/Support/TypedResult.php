<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

/**
 * @internal
 *
 * Result of one `ApiCaller::callTyped()` call: the same raw decoded body `ApiCaller::call()` has
 * always returned, PLUS the generated SDK's own typed result (`UnivaPay\Http\ApiResponse::
 * getResult()`) when one was produced. See `ApiCaller`'s class doc for why both are always
 * available together (the typed jsonmapper runs synchronously before the generated controller
 * method returns).
 */
final class TypedResult
{
    /** @var array|true Decoded raw response body -- identical to what `ApiCaller::call()` returns. */
    public $rawBody;

    /**
     * @var mixed|null The generated SDK's deserialized result object, or null when none was
     *      produced -- either because the strict jsonmapper threw (see $mapperFailed) or the
     *      controller call never reached a typed response handler at all.
     */
    public $typed;

    /**
     * @var bool True when the generated SDK's own jsonmapper threw on this response (an
     *      unmodeled/legacy shape) and `ApiCaller` fell back to the captured raw body instead of
     *      propagating the failure. `$typed` is always null when this is true.
     */
    public $mapperFailed;

    /**
     * @param array|true $rawBody
     * @param mixed|null $typed
     */
    public function __construct($rawBody, $typed, bool $mapperFailed)
    {
        $this->rawBody = $rawBody;
        $this->typed = $typed;
        $this->mapperFailed = $mapperFailed;
    }
}
