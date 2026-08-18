# Architecture: the typed-out / typed-first-in (with a raw fallback) boundary

This package sits on top of `univapay/client-sdk` (the APIMatic-generated transport engine,
namespace `UnivaPay\`) and reimplements the legacy `univapay/php-sdk`'s public surface
(namespace `Univapay\Compat\`) against it. Requests are built typed. Responses are hydrated typed
FIRST, for every resource that has opted in (see "Typed-first hydration" below) — the ported raw
`JsonSchema` path from the original compat implementation is kept as the fallback every resource
still had before, and is still the ONLY path for resources that haven't opted in yet.

```
                 ┌───────────────────────────────────────────────────────────┐
                 │                    Univapay\Compat\* (public surface)      │
                 └───────────────────────────────────────────────────────────┘
   REQUEST path                                                RESPONSE path
   (typed-out)                                          (typed-first, raw fallback)
        │                                                             ▲
        ▼                                                             │
Support\RequestModelFactory                                 Support\TypedHydrator::resolve()
  builds UnivaPay\Models\*Request                       typed: $targetClass::hydrateFromTyped()
  (typed generated request models)                       declined/threw/absent: falls back to
        │                                                Resources\*::getSchema()
        ▼                                                  ->parse($rawBody, [$context])
   UnivaPay\Apis\*Api::create/update/...()                          ▲
        │                                                 Support\ApiCaller::callTyped()
        ▼                                             { rawBody: json_decode($raw, true),
   UnivaPay\UnivapayClientSdkClient                      typed: ApiResponse::getResult() }
   (apimatic/core + apimatic/unirest-php, real HTTP)  ───────────────►
                              raw wire bytes captured by ApiCaller's HttpCallBack
                              BEFORE the generated client's own strict jsonmapper runs;
                              the typed result is whatever that jsonmapper produced (or
                              null if it threw) by the time the controller call returns
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

## Response path: typed-first, with a raw fallback

`Support\ApiCaller` registers an `HttpCallBack` whose `afterResponse` hook captures the raw
response body and status code — this fires inside `apimatic\core\ApiCall::execute()` before the
strict jsonmapper runs (see `ApiCaller`'s class doc), so the raw bytes are ALWAYS captured
regardless of what happens to the typed side. `ApiCaller::callTyped()` returns a
`Support\TypedResult` carrying both: the same raw decoded body `call()` has always returned
(`json_decode($raw, true)`), and the generated SDK's own typed result
(`UnivaPay\Http\ApiResponse::getResult()`) — already computed by the time the generated controller
method returns, since the jsonmapper runs synchronously inside `execute()` (see `ApiCaller`'s class
doc point 2). `call()` itself is unchanged — a thin `callTyped(...)->rawBody` unwrap — so any
resource that hasn't been touched behaves exactly as it did before this existed.

`Support\TypedHydrator::resolve($targetClass, $result, $context)` is what every hydration call site
funnels through:

- If `$targetClass` declares a public static `hydrateFromTyped($typed, array $rawBody, $context)`
  AND a typed result was produced, that method is called. Returning an instance uses it; returning
  `null` (a genuine spec mismatch, e.g. a required field the typed model doesn't have) or throwing
  falls back to the raw path, recording why via `Support\FallbackRegistry`.
- If `$targetClass` has no `hydrateFromTyped()` at all, `resolve()` never looks at the typed result —
  it calls `$targetClass::getSchema()->parse($rawBody, [$context])`, identical to how every resource
  hydrated before typed-first hydration existed. This is the case for every resource that hasn't
  flipped yet (see the table below).
- If the generated SDK's own jsonmapper threw `JsonMapperException` (or anything else) on a response
  shape it doesn't model, `ApiCaller::callTyped()`'s `$result->typed` is null and `$result->mapperFailed`
  is true; `resolve()` falls back to the raw path the same way, recording the occurrence (only for a
  class that actually has `hydrateFromTyped()` — an unflipped resource's normal raw operation isn't a
  "fallback" worth recording). See `tests/Hostile/` for exercises of this against real (spec-invalid)
  HTTP responses, and `tests/Unit/Differential/` for the harness that proves each flipped resource's
  typed and raw paths agree (see "The differential harness" below).

`Support\FallbackRegistry` is purely observational: `record()` never throws, logs, or emits output —
recording an occurrence is invisible to a normal consumer. It exists so tests can assert exactly
when/why a fallback engaged (an optional `setHook()` callable is also available for a consumer that
wants to observe fallbacks live, e.g. to alert on a spec gap in production).

## Typed-first hydration: which resources have flipped

A resource "flips" to typed-primary simply by declaring `hydrateFromTyped()` — no other change to
the dispatch mechanism is needed. As of this writing:

| Resource | Status | Notes |
|---|---|---|
| `Resources/Charge.php` | **Typed-primary** | Clean 1:1 field match against `UnivaPay\Models\Charge`, except `error`/`metadata` (patched from the raw body — see below) and `three_ds` (patched from the raw body — genuine spec gap, see below). |
| `Resources/Refund.php` | **Typed-primary** | Clean 1:1 match against `UnivaPay\Models\Refund`, except `error`/`metadata` (patched from the raw body). |
| `Resources/Cancel.php` | **Typed-primary** | Clean 1:1 match against `UnivaPay\Models\Cancel`, except `error`/`metadata` (patched from the raw body). |
| `Resources/PaymentToken/OnlineToken.php` | **Typed-primary** | `UnivaPay\Models\IssuerToken` already flattens both response shapes (online/d-barai vs bank_transfer) into one model with nullable fields — no gap, no union to route. |
| `Resources/PaymentToken/ThreeDSIssuerToken.php` | **Typed-primary** | Clean 1:1 match against `UnivaPay\Models\ThreeDsIssuerToken`, except `payload` (patched from the raw body). |
| `Resources/Subscription.php` | Raw-primary | **Spec gap**: the generated `UnivaPay\Models\Subscription` has no `cyclical_period`, `payments_left`, `amount_left`/`amount_left_formatted`, `subscription_plan`, `installment_plan`, or `three_ds` — fields the raw parser reads and this compat resource exposes. Patching this many fields from the raw body would leave almost nothing typed; deferred rather than flipped with a hollow typed path. |
| `Resources/Merchant.php`, `Resources/Store.php`, `Resources/CheckoutInfo.php` | Raw-primary | Share a deeply nested `Resources/Configuration/*` tree (~18 classes) against the generated `MerchantWebhookConfiguration` family. Not yet audited field-by-field for gaps; deferred as a follow-up rather than flipped without that audit. |
| `Resources/TransactionToken.php` | Raw-primary | The generated response is a 7-way discriminated union (`Card`/`Konbini`/`Online`/`BankTransfer`/`Paidy`/`QrScan`/`QrMerchant` TransactionToken, keyed on `payment_type`), and this class's own `initSchema()` picks its `data` union branch the same way (see confinement allowlist). Deferred: needs per-variant getter mapping across all 7, not yet done. |
| List-returning mixins (`Mixins\Get*`/`Support\ListDispatcher`) | Raw-primary | List endpoints return `*List` wrappers whose items are `*ListItem` models — thinner than the full entity, and compat's lazy-hydration contract (absent fields null, `fetch()` upgrades them) must produce identical nulls either way. Not yet audited for that equivalence; deferred. |
| `Resources/Transfer.php`, `Resources/BankAccount.php`, `Resources/Ledger.php`, `Resources/TransferStatusChange.php` | Raw-primary, permanently | These resources have no live API endpoint at all (`fetchCall()`/`updateCall()` throw `UnivapayUnsupportedFeatureError` unconditionally) — the ONLY place they're ever hydrated is `UnivapayClient::parseWebhookData()`, parsing a consumer-supplied array that never passed through `ApiCaller`/a generated `Apis\*` call in the first place. There is no typed result to prefer in that path, structurally, not as a matter of prioritization. |

`GenericMetadata`/`metadata` fields: preserved exactly as before regardless of a resource's
typed-primary status — always the raw decoded value verbatim, patched from `$rawBody` inside
`hydrateFromTyped()` rather than round-tripped through the typed model. Same treatment for `error`
(a raw array, not the typed `PaymentError`) and `PaymentToken\ThreeDSIssuerToken::$payload` (a raw
value, not the typed `IssuerTokenPayload`).

## The differential harness

`tests/Support/DifferentialHydration.php` hydrates one fixture two ways — the raw `JsonSchema`
path, and `hydrateFromTyped()` fed a REAL jsonmapper-deserialized generated model (via
`UnivaPay\ApiHelper::getJsonHelper()->mapClass()`, the exact mechanism the real transport engine
uses) — and asserts the two agree. `tests/Unit/Differential/*Test.php` exercises this for every
typed-primary resource above, including: `Charge`'s `three_ds` spec-gap patch, a
required-field-missing fixture per resource (proving `hydrateFromTyped()` declines and the raw
fallback throws the identical exception the raw path always has), and the `OnlineToken`
bank_transfer variant. A resource is only listed as typed-primary above once its differential
tests are green.

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
| `Resources/Charge.php`, `Resources/Refund.php`, `Resources/Cancel.php` | Typed-first hydration: `hydrateFromTyped()` patches `error`/`metadata` from the raw body by design (see "Typed-first hydration" above); `Charge`'s also patches `three_ds` (genuine spec gap) |
| `Resources/PaymentToken/ThreeDSIssuerToken.php` | Typed-first hydration: `hydrateFromTyped()` patches `payload` from the raw body by design |

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
