<?php

namespace Univapay\Compat\Errors;

use Throwable;

/**
 * New in the compat package (no old-SDK equivalent). A transport failure surfaces from the new SDK
 * as `\UnivaPay\Exceptions\ApiException` with `getCode() === 0` and `hasResponse() === false`
 * (DNS failure, connection refused, TLS error, timeout before any HTTP response). The old SDK's
 * `NetworkRetryHandler` matched on `WpOrg\Requests\Exception`, a class that never appears in the
 * new transport, so its retry logic was silently dead for this case; and mapping code-0 into
 * `UnivapayServerError(0, $url)` would mislabel a network failure as a server error. This class
 * gives Support\ExceptionMapper a distinct type to special-case code 0 + !hasResponse(),
 * and a distinct type for a ported NetworkRetryHandler to target instead.
 */
class UnivapayNetworkError extends UnivapayError
{
    public $url;

    /**
     * @param string $url The URL that was being requested when the network failure occurred.
     * @param string $message Underlying transport error message (e.g. from the HTTP client
     *                        exception that triggered this), or "" if none was available.
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct($url, $message = "", $code = 0, ?Throwable $previous = null)
    {
        $this->url = $url;
        $text = $message === ""
            ? "Network error while requesting $url"
            : "Network error while requesting $url: $message";
        parent::__construct($text, $code, $previous);
    }
}
