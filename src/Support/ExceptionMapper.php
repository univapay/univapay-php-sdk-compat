<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

use UnivaPay\Exceptions\ApiErrorException;
use UnivaPay\Exceptions\ApiException;
use Univapay\Compat\Errors\UnivapayError;
use Univapay\Compat\Errors\UnivapayForbiddenError;
use Univapay\Compat\Errors\UnivapayNetworkError;
use Univapay\Compat\Errors\UnivapayNotFoundError;
use Univapay\Compat\Errors\UnivapayRateLimitedError;
use Univapay\Compat\Errors\UnivapayRequestError;
use Univapay\Compat\Errors\UnivapayResourceConflictError;
use Univapay\Compat\Errors\UnivapayServerError;
use Univapay\Compat\Errors\UnivapayUnauthorizedError;

/**
 * @internal
 *
 * Mirrors the old SDK's Utility\HttpUtils::checkResponse() status-code switch, but maps FROM
 * the new transport engine's `\UnivaPay\Exceptions\ApiException` hierarchy TO the ported old
 * error classes, instead of from an rmccue/requests Response.
 *
 * Table (keyed strictly on ApiException::getCode(), which the generated SDK sets to the HTTP
 * status code, or 0 when no response was ever received -- see ApiException::__construct()):
 *
 *   400 -> UnivapayRequestError::fromJson()
 *   401 -> UnivapayUnauthorizedError
 *   403 -> UnivapayForbiddenError
 *   404 -> UnivapayNotFoundError
 *   409 -> UnivapayResourceConflictError
 *   429 -> UnivapayRateLimitedError
 *   0 (and !hasResponse())   -> UnivapayNetworkError
 *   anything else            -> UnivapayServerError($status, $url)
 *
 * IMPORTANT (verified against sdk/php generated code): only 400/401/403 are actually thrown as
 * `ApiErrorException` (the subclass with getStatus()/getCodeProperty()/getErrors()) by the
 * generated controllers. 404 is thrown as a PLAIN `ApiException` with no error-body accessors,
 * matching the old SDK's own behavior of not decoding a body for its 404 case either
 * (`UnivapayNotFoundError` never took a $json argument upstream). This mapper therefore branches
 * on `getCode()` only, and separately guards each body-dependent branch with
 * `instanceof ApiErrorException` rather than assuming any particular status implies any particular
 * exception shape.
 *
 * ## Two entry points (see `Support\ApiCaller`'s class doc point 3)
 *
 * `map(ApiException $e)` handles the case where the generated SDK actually THREW -- verified to
 * happen only for a genuine transport-level failure (no HTTP response at all: DNS, connection
 * refused, timeout) or, defensively, any future SDK behavior change. `mapResponse(int, mixed,
 * string)` handles the (in practice far more common) case every `Apis\*Api` method's own
 * `->returnApiResponse()` configuration actually produces for a real 4xx/5xx: a plain,
 * non-throwing `UnivaPay\Http\ApiResponse` with `isError() === true` that `Support\ApiCaller`
 * must detect itself rather than catch. Both funnel into the same status-code switch
 * (`fromStatus()`) so the two entry points can never drift into mapping the same status
 * differently.
 */
class ExceptionMapper
{
    public static function map(ApiException $e): UnivapayError
    {
        $url = $e->getHttpRequest()->getQueryUrl();
        $status = $e->getCode();

        if ($status === 0 && !$e->hasResponse()) {
            return new UnivapayNetworkError($url, $e->getMessage());
        }

        return self::fromStatus($status, self::bodyAsArray($e), $url);
    }

    /**
     * Maps a non-throwing `ApiResponse::isError()` result (see class doc "Two entry points") --
     * the path every real HTTP 4xx/5xx from this transport engine actually takes.
     *
     * @param int $statusCode `$response->getStatusCode()`.
     * @param mixed $decodedBody `Support\ApiCaller`'s own raw-body-captured decode (an assoc
     *        array on a JSON object body, or something else entirely on a malformed/non-object
     *        error body -- non-array inputs are treated as "no body" rather than crashing).
     * @param string $url `$response->getRequest()->getQueryUrl()`.
     */
    public static function mapResponse(int $statusCode, $decodedBody, string $url): UnivapayError
    {
        $bodyArray = is_array($decodedBody) ? $decodedBody : [];
        return self::fromStatus($statusCode, $bodyArray, $url);
    }

    private static function fromStatus(int $status, array $bodyArray, string $url): UnivapayError
    {
        switch ($status) {
            case 400:
                return UnivapayRequestError::fromJson($url, $bodyArray);

            case 401:
                return new UnivapayUnauthorizedError($url, $bodyArray);

            case 403:
                return new UnivapayForbiddenError($url, $bodyArray);

            case 404:
                return new UnivapayNotFoundError($url);

            case 409:
                return new UnivapayResourceConflictError($url, $bodyArray);

            case 429:
                return new UnivapayRateLimitedError($url);

            default:
                return new UnivapayServerError($status, $url);
        }
    }

    /**
     * Reconstructs the old wire-shape `['status' => ..., 'code' => ..., 'errors' => ...]` array
     * that the ported error constructors (UnivapayRequestError and its 401/403/409 subclasses)
     * expect as their $json argument.
     *
     * - `ApiErrorException` (400/401/403 per the generated code): built from its typed
     *   accessors -- this is the normal, fully-deserialized path.
     * - Plain `ApiException` with a response (any status this mapper still needs a body for,
     *   defensively, in case a future SDK regen changes which statuses throw which shape):
     *   falls back to json_decode()'ing the raw response body directly, same as old HttpUtils
     *   did with `json_decode($response->body, true)`.
     * - No response at all (network failure): empty array: the 400/401/403/409 branches above
     *   are unreachable without a response in practice, but this keeps the helper total.
     */
    private static function bodyAsArray(ApiException $e): array
    {
        if ($e instanceof ApiErrorException) {
            return [
                'status' => $e->getStatus(),
                'code' => $e->getCodeProperty(),
                'errors' => $e->getErrors()
            ];
        }

        if ($e->hasResponse()) {
            $decoded = json_decode($e->getHttpResponse()->getRawBody(), true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
