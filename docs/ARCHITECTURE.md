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
| `Resources/Charge.php` | **Typed-primary** | Clean 1:1 field match against `UnivaPay\Models\Charge`, except `error`/`metadata` (patched from the raw body — see below) and `three_ds` (patched from the raw body — genuine spec gap, see below). List items (`ChargeList::getItems()`) too -- see "List endpoints" below. |
| `Resources/Refund.php` | **Typed-primary** | Clean 1:1 match against `UnivaPay\Models\Refund`, except `error`/`metadata` (patched from the raw body). List items too. |
| `Resources/Cancel.php` | **Typed-primary** | Clean 1:1 match against `UnivaPay\Models\Cancel`, except `error`/`metadata` (patched from the raw body). List items too. |
| `Resources/PaymentToken/OnlineToken.php` | **Typed-primary** | `UnivaPay\Models\IssuerToken` already flattens both response shapes (online/d-barai vs bank_transfer) into one model with nullable fields — no gap, no union to route. |
| `Resources/PaymentToken/ThreeDSIssuerToken.php` | **Typed-primary** | Clean 1:1 match against `UnivaPay\Models\ThreeDsIssuerToken`, except `payload` (patched from the raw body). |
| `Resources/TransactionToken.php` | **Typed-primary** | 7-way discriminated union (`Card`/`Konbini`/`Online`/`BankTransfer`/`Paidy`/`QrScan`/`QrMerchant` TransactionToken, keyed on `payment_type`) — narrowed via `instanceof` against all 7 (no shared parent type), `data` dispatched per variant to the matching `PaymentData\*::hydrateFromTyped()`. Two spec gaps patched from raw: `ip_address` (no generated variant carries it at all) and `PaidyData`'s shipping `country` (the generated shipping-address sub-model has no `country` getter). `bank_transfer` has no compat `PaymentData\*` class at all — a pre-existing raw-path gap (the raw switch has no case for it either), matched exactly rather than introduced. `apple_pay` is not a real wire discriminator value (the union's spec enum has no such entry — Apple Pay tokens report `payment_type: "card"`), so it always forces the raw fallback, by design. List items are a genuinely thinner `TransactionTokenListItem` (no `data`/`confirmed`/`usage_limit`/`last_used_on`/`metadata`/`ip_address` at all) — **not flipped**, see "List endpoints" below. |
| `Resources/Merchant.php` | **Typed-primary** | Clean 1:1 match against `UnivaPay\Models\Merchant`; `configuration` (required) dispatched to `Configuration::hydrateFromTyped()`. |
| `Resources/Store.php` | **Typed-primary** | Clean 1:1 match against `UnivaPay\Models\Store`; `configuration` (optional, unlike `Merchant`'s required one) dispatched to `Configuration::hydrateFromTyped()`. |
| `Resources/Configuration/*` (13 of 18 classes) | **Typed-primary** | Field-by-field audit against the generated `MerchantWebhookConfiguration` family (see below) found every field has a typed counterpart except a handful always stored raw by design, plus one real bug (see below) — flipped: `Configuration`, `TransferSchedule`, `UserTransactionsConfiguration`, `CardConfiguration`, `QrScanConfiguration`, `ConvenienceConfiguration`, `PaidyConfiguration`, `RecurringConfiguration`, `CardChargeCvvConfirmation`, `SecurityConfiguration`, `LimitChargeByCardConfiguration`, `InstallmentsConfiguration`, `CardBrandPercentFees`. |
| `Resources/CheckoutInfo.php` and its own-only `Configuration/*` classes (`OnlineConfiguration`, `SubscriptionConfiguration`, `SupportedBrand`, `ThemeConfiguration`, `ColorsConfiguration`) | Raw-primary | `GET /checkout_info` has its OWN separate generated model family (`Checkout*`, e.g. `CheckoutCardConfiguration`), distinct from `MerchantWebhookConfiguration` — NOT audited. `CheckoutInfo` has no `hydrateFromTyped()` of its own, so `TypedHydrator` never attempts typed hydration for it regardless of what the *shared* Configuration classes above now support (their `hydrateFromTyped()` only recognizes the `MerchantWebhookConfiguration` family and declines harmlessly for anything else — see e.g. `ConvenienceConfiguration`'s own doc). |
| `Resources/Subscription.php` | Raw-primary | **Confirmed spec gap** (a spec fix is in flight separately): the generated `UnivaPay\Models\Subscription` has no `cyclical_period`, `cycles_left`/`payments_left`, `charge_id`, `subscription_plan`, `installment_plan`, `amount_left`/`amount_left_formatted`, or `three_ds` — fields the raw parser reads and this compat resource exposes. Do not patch-from-raw here: patching this many fields would leave almost nothing typed, so this stays raw-primary until the spec catches up. |
| List endpoints (`Support\ListDispatcher`) | Mixed — see below | Charge/Refund/Cancel lists are typed-first; every other list endpoint is raw-primary. |
| `Resources/Transfer.php`, `Resources/BankAccount.php`, `Resources/Ledger.php`, `Resources/TransferStatusChange.php` | Raw-primary, permanently | These resources have no live API endpoint at all (`fetchCall()`/`updateCall()` throw `UnivapayUnsupportedFeatureError` unconditionally) — the ONLY place they're ever hydrated is `UnivapayClient::parseWebhookData()`, parsing a consumer-supplied array that never passed through `ApiCaller`/a generated `Apis\*` call in the first place. There is no typed result to prefer in that path, structurally, not as a matter of prioritization. |

`GenericMetadata`/`metadata` fields: preserved exactly as before regardless of a resource's
typed-primary status — always the raw decoded value verbatim, patched from `$rawBody` inside
`hydrateFromTyped()` rather than round-tripped through the typed model. Same treatment for `error`
(a raw array, not the typed `PaymentError`), `PaymentToken\ThreeDSIssuerToken::$payload`/
`PaymentData\CardData`'s `three_ds.error` (raw, not the typed `IssuerTokenPayload`/`PaymentError`),
and `Configuration`'s `flat_fees`/`min_transfer_payout`/`maximum_charge_amounts` plus
`CardChargeCvvConfirmation.threshold`/`InstallmentsConfiguration.min_charge_amount` (raw, never
Money-converted, even before typed-first hydration existed).

## Configuration tree audit findings

`Resources/Configuration/*` nests under both `Merchant`/`Store` (`configuration`, backed by the
generated `MerchantWebhookConfiguration` family) and `CheckoutInfo` (its own separate `Checkout*`
family — see the table above). The audit checked every `Configuration\*` class nested under
`Merchant`/`Store` (13 of the 18 total classes; the other 5 are `CheckoutInfo`-only, not touched)
field by field against the generated model. Findings:

- **13 classes have a clean typed counterpart for every field** except a handful always stored raw
  by design (see the `GenericMetadata` note above) — all 13 are now typed-primary.
- **One real bug, found and deliberately preserved**: `QrScanConfiguration::$forbiddenQrScanGateway`
  reads the WRONG wire key. The auto-derived raw schema has always read
  `forbidden_qr_scan_gateway` (singular, from the property name), but the generated model's own
  `@maps` annotation for the equivalent field is `forbidden_qr_scan_gateways` (**plural** — see
  `UnivaPay\Models\MerchantWebhookQrScanConfiguration::setForbiddenQrScanGateways()`). The real
  field name evidently changed upstream and the raw path never caught up, so this property has
  always been `null` in practice. `hydrateFromTyped()` reads the SAME (singular, wrong) key from
  the raw body on purpose — using the typed model's own correctly-keyed getter would silently
  start returning real data, a behavior change typed-first hydration must not introduce. See
  `QrScanConfiguration`'s own class doc and
  `ConfigurationDifferentialTest::testQrScanForbiddenGatewaysWireKeyMismatchIsPreservedAsNullOnBothPaths()`.
- **`CheckoutInfo`'s own `Configuration/*`-adjacent classes were not audited** — a genuinely
  separate generated model family (`Checkout*`), not a subset of `MerchantWebhookConfiguration`.
  Deferred; `CheckoutInfo` stays entirely raw-primary.

## List endpoints

`Support\ListDispatcher::wrapPage()` accepts an optional `$typedItems` array (the generated list
wrapper's own `getItems()`, positionally aligned with the raw `items`) and passes the item at the
same index as a second argument to `$itemParser` — existing single-parameter item parsers are
unaffected (PHP ignores the extra argument), so flipping one endpoint never touches another.

- **Charge/Refund/Cancel lists are typed-first** (`listAllCharges`/`listStoreCharges`/
  `listSubscriptionCharges`/`listChargesForSubscriptionPayment`/`listRefunds`/`listCancels`):
  `UnivaPay\Models\ChargeList`/`RefundList`/`CancelList::getItems()` return the SAME full
  `Charge[]`/`Refund[]`/`Cancel[]` typed models the single-fetch endpoints already use — there is
  no separate, thinner `*ListItem` type for these three, so the existing `hydrateFromTyped()`
  methods apply unchanged per item, with no additional gap audit needed. Each call site's item
  parser now routes through `Support\TypedHydrator::resolve()` instead of a bare
  `getSchema()->parse()`.
- **Every other list endpoint stays raw-primary** — transaction tokens, subscriptions, stores,
  transactions, scheduled payments, bank transfer ledgers. Not all were audited; the one that was,
  transaction tokens, rules itself out concretely: `UnivaPay\Models\TransactionTokenListItem` is
  genuinely thinner than the full union response — it carries `id`/`storeId`/`merchantName`/
  `storeName`/`email`/`paymentType`/`active`/`mode`/`type`/`createdOn`/`updatedOn`/`userData` only,
  with no `data`, `confirmed`, `usage_limit`, `last_used_on`, `metadata`, or `ip_address` field at
  all. Flipping this list would need dedicated `TransactionTokenListItem`-aware hydration (skip the
  `data`-union dispatch entirely rather than reaching for a `getData()` that doesn't exist) rather
  than reusing `TransactionToken::hydrateFromTyped()` as-is; not implemented.

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
| `Resources/Store.php` | `getCustomerId()` returns `$body['customer_id']` directly — no `Jsonable` hydration step; also `hydrateFromTyped()`'s `configuration` sub-body patch |
| `Resources/Charge.php`, `Resources/Refund.php`, `Resources/Cancel.php` | Typed-first hydration: `hydrateFromTyped()` patches `error`/`metadata` from the raw body by design (see "Typed-first hydration" above); `Charge`'s also patches `three_ds` (genuine spec gap) |
| `Resources/PaymentToken/ThreeDSIssuerToken.php` | Typed-first hydration: `hydrateFromTyped()` patches `payload` from the raw body by design |
| `Resources/PaymentData/CardData.php` | Typed-first hydration: `hydrateFromTyped()` patches `three_ds.error` from the raw body by design |
| `Resources/PaymentData/PaidyData.php` | Typed-first hydration: `hydrateFromTyped()` patches the shipping address's `country` from the raw body — genuine spec gap (the generated shipping-address sub-model has no `country` getter) |
| `Resources/Merchant.php` | Typed-first hydration: `hydrateFromTyped()`'s `configuration` sub-body patch |
| `Resources/Configuration/Configuration.php` | Typed-first hydration: `hydrateFromTyped()` patches `flat_fees`/`min_transfer_payout`/`maximum_charge_amounts` from the raw body by design, plus sub-body extraction for each nested config |
| `Resources/Configuration/CardChargeCvvConfirmation.php` | Typed-first hydration: `hydrateFromTyped()` patches `threshold` from the raw body by design |
| `Resources/Configuration/InstallmentsConfiguration.php` | Typed-first hydration: `hydrateFromTyped()` patches `min_charge_amount` from the raw body by design |
| `Resources/Configuration/QrScanConfiguration.php` | Typed-first hydration: `hydrateFromTyped()` patches `forbidden_qr_scan_gateway` from the raw body — preserving a pre-existing wire-key bug, see "Configuration tree audit findings" |
| `Resources/Configuration/RecurringConfiguration.php` | Typed-first hydration: sub-body extraction for its nested `CardChargeCvvConfirmation` |

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
