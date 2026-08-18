<?php

namespace Univapay\Compat\Requests\Handlers;

use Closure;

/**
 * Verbatim port (namespace line only) of the old SDK's `Requests\Handlers\RequestHandler`.
 *
 * Unlike the old SDK, the `$request` closure this wraps no longer performs a raw HTTP call --
 * `Support\ApiCaller` runs the cascade over a synthetic, read-only `$requestData` tuple (see its
 * class doc) around one generated-SDK controller invocation. The contract handlers implement
 * against is otherwise identical: call `$request($requestData)` to continue the chain (any
 * number of times, e.g. for a retry), and always return what it returns.
 */
interface RequestHandler
{
    /**
     * Handles the pre and post of a request. To execute the chain, pass the $requestData as
     * as the first parameter into $request. Always return the result from $request.
     * @param Closure $request The request chain. Execute it by passing the $requestData as the first parameter
     * @param array $requestData A synthetic, read-only tuple threaded through the cascade purely to
     * satisfy this interface's contract -- Support\ApiCaller's actual call arguments are bound
     * into the $request closure itself, not carried in this array.
     * @return mixed Whatever $request(...) returns.
     */
    public function handle(Closure $request, array $requestData);
}
