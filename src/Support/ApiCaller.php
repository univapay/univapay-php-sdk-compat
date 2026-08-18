<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

use Closure;
use Throwable;
use UnivaPay\Exceptions\ApiException;
use UnivaPay\Http\ApiResponse;
use UnivaPay\Http\HttpCallBack;
use Univapay\Compat\Errors\UnivapayError;
use Univapay\Compat\Requests\Handlers\RequestHandler;

/**
 * @internal
 *
 * Runs one logical compat-layer API call through the ported request-handler cascade
 * (Requests/Handlers/*) against the new transport engine, and is where these semantic-parity fixes
 * live:
 *
 * 1. Idempotency across retries: a single idempotency key is generated ONCE per logical call,
 *    OUTSIDE the retry cascade, and handed to `$controllerFn` on every attempt -- including
 *    every retry. The generated SDK's `UnivaPay\IdempotencyCallback` only auto-generates a fresh
 *    key when the `Idempotency-Key` header is absent, so passing the same key explicitly on
 *    every attempt (instead of leaving it null and letting the generated client mint a new
 *    UUIDv4 per HTTP request) is what prevents a retried-but-actually-processed POST from
 *    creating duplicate resources. See old `Utility\HttpUtils::getIdempotencyHeader()`
 *    (`uniqid('', true)`), ported as `generateIdempotencyKey()` below.
 *
 * 2. Raw-body capture bypassing strict deserialization: `httpCallback()` returns an
 *    `HttpCallBack` meant to be registered on the generated client (see `Bridge`). Its
 *    `afterResponse` hook fires INSIDE `Core\ApiCall::execute()` *before* the typed jsonmapper
 *    runs (`$this->coreClient->afterResponse($context)` precedes
 *    `$this->responseHandler->getResult($context)` -- verified in
 *    vendor/apimatic/core/src/ApiCall.php:44-52), so the raw wire bytes are always captured even
 *    when the strict mapper subsequently throws `apimatic\jsonmapper\JsonMapperException` (e.g.
 *    on a legacy metadata shape, or a QR/Paidy token `data` variant the spec doesn't describe
 *    yet). `call()` catches any such non-`ApiException`,
 *    non-`UnivapayError` throwable and, if the last captured status was 2xx, answers from the
 *    captured bytes instead of failing the call outright.
 *
 * 3. **HTTP-level errors never throw at all -- they come back as `ApiResponse::isError()`.**
 *    Verified empirically against a real local HTTP server, not assumed from reading generated
 *    code: every single generated `Apis\*Api` method's response handler is
 *    built with `->returnApiResponse()` (grepped: literally every controller file in
 *    sdk/php/src/Apis/*.php ends every operation's handler chain with it). Per
 *    `apimatic/core`'s own `Core\Response\ResponseHandler::getResult()` /
 *    `Core\Response\ResponseError::getApiResponse()`, that means a 4xx/5xx response is NEVER
 *    thrown as a `\UnivaPay\Exceptions\ApiException` by calling e.g. `$chargesApi->getCharge(...)`
 *    directly -- it comes back as a plain, non-throwing `UnivaPay\Http\ApiResponse` whose
 *    `isError()` is `true` and whose `getResult()` is just the decoded error body as an array.
 *    (A genuine transport-level failure -- DNS, connection refused, timeout before any response
 *    -- is a DIFFERENT code path with no `Context`/`ApiResponse` to construct at all, and DOES
 *    throw `ApiException` directly, code 0, `hasResponse() === false`; that path is unaffected
 *    and still handled by the `catch (ApiException $e)` block below.) Before this fix, every
 *    `$controllerFn` closure across the resource layer called its generated method and DISCARDED
 *    the return value entirely (`function () use ($charges) { $charges->getCharge(...); }`), so
 *    an HTTP error response was silently treated as a success and handed to a resource's
 *    `initSchema()` parser as if it were the real resource shape -- surfacing as a confusing
 *    `Utility\Json\NoSuchPathException`/`TypeError` instead of the intended `Errors\*` exception.
 *    Fixed two ways, together: every `$controllerFn` closure in the resource layer now `return`s
 *    the generated call's result (a mechanical, behavior-preserving change for the success case --
 *    the return value was always ignored on that path anyway), and `call()` below inspects that
 *    returned `ApiResponse` for `isError()` and maps it via `ExceptionMapper::mapResponse()`
 *    (a NEW method, parallel to `map(ApiException)`, working off the response's own captured
 *    status code + raw body rather than an exception object). See
 *    tests/Hostile/MalformedErrorBodyTest.php and every tests/Integration/ test that exercises an
 *    error path for the empirical proof; docs/ARCHITECTURE.md notes this as the reason
 *    `ExceptionMapper` has two entry points instead of one.
 *
 * Composition with the generated client's own `IdempotencyCallback`: `UnivapayClientSdkClient`'s
 * constructor always wraps whatever `httpCallback` config value it is given inside its own
 * `IdempotencyCallback`, which forwards both the before- and after-request hooks to the wrapped
 * callback after doing its own idempotency-header work. Registering this class's `httpCallback()`
 * on the builder (see `Bridge`) therefore means: `IdempotencyCallback::onBeforeRequest` injects a
 * fresh UUID *only if* no `Idempotency-Key` header is already present (it never is here, since
 * `$controllerFn` above always supplies the one generated by `call()`), then
 * `IdempotencyCallback::onAfterRequest` delegates to this class's `onAfterRequest`, which records
 * the raw body. Nothing about this class depends on `IdempotencyCallback` directly -- it is
 * simply "some `httpCallback` the generated client wraps," per its constructor.
 */
final class ApiCaller
{
    /** @var string|null */
    private $lastRawBody;

    /** @var int|null */
    private $lastStatusCode;

    /** @var HttpCallBack|null */
    private $httpCallback;

    public function __construct()
    {
        $this->lastRawBody = null;
        $this->lastStatusCode = null;
    }

    /**
     * The callback to register on the generated client (`UnivapayClientSdkClientBuilder::
     * httpCallback()`, see `Bridge`'s constructor). Memoized: the same instance must be reused
     * across the client's lifetime since it is this object's afterResponse hook that keeps this
     * ApiCaller's capture state in sync with the client's actual HTTP traffic.
     */
    public function httpCallback(): HttpCallBack
    {
        if ($this->httpCallback === null) {
            $this->httpCallback = new HttpCallBack(null, function ($context): void {
                $this->recordResponse(
                    $context->getResponse()->getRawBody(),
                    $context->getResponse()->getStatusCode()
                );
            });
        }
        return $this->httpCallback;
    }

    /**
     * Records the most recently observed raw response body + status code. This is the real
     * production entry point the httpCallback() closure above delegates to; it is also public so
     * unit tests can simulate "a response just arrived" without needing a real HTTP client (see
     * tests/Unit/Support/ApiCallerTest.php).
     */
    public function recordResponse(string $rawBody, int $statusCode): void
    {
        $this->lastRawBody = $rawBody;
        $this->lastStatusCode = $statusCode;
    }

    private function resetCapture(): void
    {
        $this->lastRawBody = null;
        $this->lastStatusCode = null;
    }

    /**
     * @param callable $controllerFn Receives ONE argument: the idempotency key generated once for
     *        this logical call and reused, unchanged, on every retry attempt (see class doc point 1
     *        above). MUST `return` the generated controller method's own result (a
     *        `UnivaPay\Http\ApiResponse` -- see class doc point 3), e.g.
     *        `function ($idempotencyKey) use ($bridge, $storeId, $id) {
     *            return $bridge->charges()->getCharge($storeId, $id);
     *        }`.
     *        On a SUCCESS response, that returned value itself is still ignored -- `call()`
     *        answers from the raw response body captured via `httpCallback()`, never from the
     *        generated SDK's typed result, so that a strict-mapper failure on an unmodeled
     *        response shape (see class doc point 2) never has to be worked around by callers. On an
     *        ERROR response (`$response->isError()`), the returned `ApiResponse` IS what `call()` uses
     *        to detect and map the error (see class doc point 3) -- a closure that discards it
     *        would silently treat every HTTP error as a success.
     * @param RequestHandler[] $handlers The cascade to run the call through, in old
     *        `UnivapayClientOptions::getRequestHandlers()` array order: index 0 is innermost, the
     *        LAST element is OUTERMOST. This is not reversed from the old SDK's own array order --
     *        `encapsulate()` below reduces left-to-right and each step wraps the previous one, so
     *        the last-reduced (last array element) ends up outermost. Concretely, the default
     *        `[$rateLimitHandler, $networkRetryHandler]` means `NetworkRetryHandler` is outermost
     *        (its retry loop re-runs the whole `RateLimitHandler`-wrapped attempt, worst case
     *        `tries_network * tries_rateLimit` real attempts) and `RateLimitHandler` is inner --
     *        matching old `HttpRequester`/`UnivapayClient` semantics exactly, including
     *        `addHandlers()` appending new OUTERMOST layers and `setHandlers()` replacing the
     *        whole cascade with `[...default handlers, ...given handlers]` (see `Bridge`).
     * @param string $urlHint Diagnostic-only label (e.g. the route being called) threaded through
     *        as the synthetic `$requestData` handlers receive, and used in the wrapped-error
     *        message on the "rethrow wrapped" path below. Handlers never inspect its content.
     *
     * @return mixed Decoded raw response body (assoc array via `json_decode(..., true)`), or
     *         `true` for an empty body -- matching old `Utility\HttpUtils::checkResponse()`'s
     *         "NOTE: json_decode would return null for boolean false" handling of an empty body.
     *
     * @throws \Univapay\Compat\Errors\UnivapayError On any mapped API error, or on an
     *         unrecoverable non-API throwable (see class doc point 2's "else rethrow wrapped").
     */
    public function call(callable $controllerFn, array $handlers, string $urlHint)
    {
        $idempotencyKey = self::generateIdempotencyKey();

        $innermost = function (array $requestData) use ($controllerFn, $idempotencyKey, $urlHint) {
            $this->resetCapture();
            try {
                $response = $controllerFn($idempotencyKey);
            } catch (ApiException $e) {
                // Real transport-level failure (network error, code 0 -- see class doc point 3)
                // or a future SDK regen that throws directly for some other reason.
                throw ExceptionMapper::map($e);
            } catch (Throwable $t) {
                if ($t instanceof UnivapayError) {
                    throw $t;
                }
                if ($this->hasBypassableCapture()) {
                    return $this->decodeCapturedBody();
                }
                throw new UnivapayError(
                    "Unexpected error while handling the response for $urlHint: " . $t->getMessage(),
                    0,
                    $t
                );
            }
            // Class doc point 3: every generated controller method returns a non-throwing
            // ApiResponse -- a 4xx/5xx never reaches the catch blocks above at all, it has to be
            // detected here instead.
            if ($response instanceof ApiResponse && $response->isError()) {
                throw ExceptionMapper::mapResponse(
                    $response->getStatusCode() ?? 0,
                    $this->decodeCapturedBody(),
                    $response->getRequest()->getQueryUrl()
                );
            }
            return $this->decodeCapturedBody();
        };

        $encapsulated = self::encapsulate($handlers, $innermost);
        return $encapsulated([$urlHint]);
    }

    private function hasBypassableCapture(): bool
    {
        return $this->lastStatusCode !== null
            && $this->lastStatusCode >= 200
            && $this->lastStatusCode < 300
            && $this->lastRawBody !== null;
    }

    /**
     * @return mixed
     */
    private function decodeCapturedBody()
    {
        if ($this->lastRawBody === null || $this->lastRawBody === '') {
            return true;
        }
        return json_decode($this->lastRawBody, true);
    }

    /**
     * Port of old `Utility\HttpUtils::getIdempotencyHeader()`'s key generation
     * (`uniqid('', true)`), split out from the header-array wrapping since the new SDK's
     * generated controllers take the key as a plain string argument, not a header array.
     */
    public static function generateIdempotencyKey(): string
    {
        return uniqid('', true);
    }

    /**
     * Port of the old SDK's `Requests\HttpRequester::encapsulate()`. Identical reduce-based
     * nesting: handlers at higher array indices wrap ones at lower indices, so the LAST handler
     * in $handlers is outermost / first to receive control, and the first handler in $handlers is
     * innermost / closest to the actual call. See this class's and `RequestHandler`'s docs.
     */
    private static function encapsulate(array $handlers, Closure $request): Closure
    {
        return array_reduce($handlers, function (Closure $request, RequestHandler $handler) {
            return function (array $requestData) use ($request, $handler) {
                return $handler->handle($request, $requestData);
            };
        }, $request);
    }
}
