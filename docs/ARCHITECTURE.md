# Architecture: the typed-out / raw-in boundary

This package sits on top of `univapay/client-sdk` (the APIMatic-generated transport engine,
namespace `UnivaPay\`) and reimplements the legacy `univapay/php-sdk`'s public surface
(namespace `Univapay\Compat\`) against it. Requests are built typed; responses are hydrated raw.

```
                 ┌───────────────────────────────────────────────────────────┐
                 │                    Univapay\Compat\* (public surface)      │
                 └───────────────────────────────────────────────────────────┘
   REQUEST path                                                RESPONSE path
   (typed-out)                                                  (raw-in)
        │                                                             ▲
        ▼                                                             │
Support\RequestModelFactory                                 Resources\*::getSchema()
  builds UnivaPay\Models\*Request                              ->parse($body, [$context])
  (typed generated request models)                              via Utility\Json\JsonSchema
        │                                                             ▲
        ▼                                                             │
   UnivaPay\Apis\*Api::create/update/...()                 Support\ApiCaller::call()
        │                                                    json_decode($rawBody, true)
        ▼                                                             ▲
   UnivaPay\UnivapayClientSdkClient                                    │
   (apimatic/core + apimatic/unirest-php, real HTTP)  ───────────────►
                              raw wire bytes captured by ApiCaller's HttpCallBack
                              BEFORE the generated client's own strict jsonmapper runs
```

## Request path

`Support\RequestModelFactory` converts a `Univapay\Compat\*` call's arguments into a typed
`UnivaPay\Models\*Request` generated model. That typed request goes through
`UnivaPay\Apis\*Api::create()`/`update()`/etc., which serializes and sends it via
`UnivaPay\UnivapayClientSdkClient`'s real HTTP transport (`apimatic/core` +
`apimatic/unirest-php`).

## Error mapping

Every generated `UnivaPay\Apis\*Api` method is configured with `->returnApiResponse()`: an HTTP
error response (4xx/5xx) does not throw — it comes back as a non-throwing
`UnivaPay\Http\ApiResponse` whose `isError()` is `true`, `getStatusCode()` returns the real status,
and `getResult()` is the decoded error body. Only a genuine transport-level failure (DNS,
connection refused, timeout before any response) throws `\UnivaPay\Exceptions\ApiException`
directly, with `getCode() === 0`.

`Support\ApiCaller::call()` checks every response's `isError()` and maps it via
`Support\ExceptionMapper::mapResponse(int $statusCode, $decodedBody, string $url)` into the
`Errors\*` hierarchy (`UnivapayNotFoundError`, `UnivapayRequestError`, etc). Transport failures are
mapped separately via `ExceptionMapper::map(ApiException $e)`. Both entry points funnel into the
same status-code switch, so the two paths cannot drift apart. See `Support\ApiCaller`'s and
`Support\ExceptionMapper`'s own class docs for the exact mechanism, and
`tests/Hostile/MalformedErrorBodyTest.php` plus `tests/Integration/`'s error-path assertions for
coverage.

## Response path (raw-in) and fallback semantics

The generated SDK's own typed response models (`UnivaPay\Models\Charge`, etc.) are never used to
hydrate a compat resource. `Support\ApiCaller` registers an `HttpCallBack` whose `afterResponse`
hook captures the raw response body and status code — this fires inside
`apimatic\core\ApiCall::execute()` before the strict jsonmapper runs (see `ApiCaller`'s class doc).
`ApiCaller::call()` always resolves from that captured raw body (`json_decode($raw, true)`), and a
compat resource's own `initSchema()` (a ported old-SDK `JsonSchema` definition) walks the decoded
array by reflection to build typed public properties (`Money`, `TypedEnum`, `DateTime`, nested
resources) — the same parsing behavior as the old SDK.

If the generated SDK's own jsonmapper throws `JsonMapperException` on a response shape it doesn't
model, `ApiCaller::call()` catches it and falls back to the same raw-decode-and-hydrate path
instead of propagating the mapper failure. See `tests/Hostile/` for exercises of this fallback
against real (spec-invalid) HTTP responses.

## The confinement allowlist

Raw array access on a decoded HTTP body (`$body[`, `$json[`, `$raw[`, `$decoded[`, `$response[`,
`$payload[`) is confined to an explicit allowlist, enforced by
`tests/Unit/Architecture/RawJsonConfinementTest.php` (a grep-based test).

| File | Why it's raw |
|---|---|
| `Support/ApiCaller.php` | Captures and decodes the raw response body |
| `Utility/Json/*` | The ported `JsonSchema` hydration machinery every resource's `initSchema()` runs through |
| `Utility/FormatterUtils.php` | `getMoney()`'s currency-lookup closure, called from `initSchema()` bodies |
| `Resources/Authentication/AppJWT.php` | Decodes the JWT's own payload segment (not an API response) |
| `Resources/TransactionToken.php` | `initSchema()`'s `data` upsert closure reads `$json['payment_type']` to pick the payment-type union branch |
| `Support/ExceptionMapper.php` | `bodyAsArray()`'s plain-`ApiException`-with-response fallback (its own `ALLOWED_JSON_DECODE` row); array-building elsewhere in the class is typed-accessor code, not bracket access into a raw body |
| `Errors/UnivapayRequestError.php` + 401/403/409 subclasses | `fromJson()`/constructors read the array `ExceptionMapper::mapResponse()`/`map()` built |
| `Support/ListDispatcher.php` | `wrapPage()` reads `$decoded['items']`/`['has_more']`; `resolveMerchantId()` reads `$decoded['id']` from a raw `GET /me` |
| `Resources/Store.php` | `getCustomerId()` returns `$body['customer_id']` directly — no `Jsonable` hydration step |

`UnivapayClient::parseWebhookData(array $data)` reads `$data['event']`/`$data['data']` — but on a
consumer-supplied array (that method's documented contract), never a decoded API response. It
needs no allowlist entry: the confinement test's array-access grep does not match `$data[` (see
that test's own class doc).

## Out of scope for the confinement test

`Support/RequestModelFactory.php` does extensive `$data['...']` array access, but that's the
request-building half of the architecture — converting a `PaymentData\*`/`PaymentMethod\*`
object's own internal `$data` array into a typed `UnivaPay\Models\*Request`, never a decoded HTTP
response. The confinement test's grep does not match `$data[` as a variable name for this reason
(see the test's own class doc). The one place a real HTTP-response-shaped payload happens to be
named `$data` — `UnivapayClient::parseWebhookData(array $data)` — is allowlisted explicitly by
file, not by matching that variable name generally.
