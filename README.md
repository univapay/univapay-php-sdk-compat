# univapay/univapay-sdk-compat

[日本語版はこちら](README.ja.md)

A runtime compatibility layer that reimplements the public surface of the legacy, hand-written
[`univapay/php-sdk`](https://github.com/univapay/univapay-php-sdk) — the same class names, method
signatures, public properties, enum style, exceptions, and polling behavior — on top of
[`univapay/client-sdk`](https://github.com/univapay/univapay-client-php-sdk) (the new,
APIMatic-generated transport engine) as its transport. It exists so that a codebase migrated by
[`univapay/univapay-sdk-migrate`](https://github.com/univapay/univapay-php-sdk-migrate) keeps
working, unmodified, on the new engine.

## What this is

`univapay/php-sdk` will not be updated again. Every new API feature (v1.1 and later) lands only in
`univapay/client-sdk`. This package lets existing integrators reach that new engine without
rewriting call sites: it is a drop-in, namespace-only swap — `Univapay\*` → `Univapay\Compat\*` —
that keeps every construct your code already uses (`Money\Money` values, `ChargeStatus::SUCCESSFUL()`
identity comparisons, public property access, `awaitResult()`/chained calls, catch blocks) behaving
exactly as it did before. Internally, every compat method builds a typed request against
`univapay/client-sdk` and sends it through that engine's real HTTP transport — nothing here talks to
the API directly. See [Architecture](#architecture) for how responses are then hydrated back into
the old SDK's shapes.

You do not install this package by hand in the normal case — see [Install](#install).

## Install

**Normal path: run the migration tool.** `univapay/univapay-sdk-migrate` requires this package,
rewrites your code's imports to point at it, and removes `univapay/php-sdk`, all in one command:

```bash
composer require --dev univapay/univapay-sdk-migrate
vendor/bin/univapay-migrate
```

See the [`univapay-sdk-migrate` README](https://github.com/univapay/univapay-php-sdk-migrate) for
what that command does, its flags, and its report format.

**Manual path.** If you are not using the migration tool — a fresh integration deliberately
targeting the old SDK's API shape, or a codebase already rewritten by hand — install directly:

```bash
composer require univapay/univapay-sdk-compat
```

The two packages *can* be installed side by side — their autoload roots (`Univapay\` vs.
`Univapay\Compat\`) don't collide, and this package never references an old-SDK class directly.
`univapay-sdk-migrate` relies on that: it requires this package before removing `univapay/php-sdk`,
so both are present for one step while Rector still needs the old SDK's classes loadable for
receiver-type resolution. Once your migration is done, remove `univapay/php-sdk` — there's no
reason to keep it installed, but nothing breaks if it lingers alongside this package. Requires PHP
`>=7.2` (matching `univapay/client-sdk`'s own floor) and `moneyphp/money` `^3.3 || ^4.0`.

## Supported surface matrix

Almost everything the old SDK exposed works. A small, fixed set of methods compile and are
reachable but throw `Errors\UnivapayUnsupportedFeatureError` at call time, because the new engine
has no equivalent API to call through to:

| Area | Status | Notes |
|---|---|---|
| Charges, Refunds, Cancels | Live | Full lifecycle, including the two-step token-GET-then-create preflight the old SDK performed. |
| Subscriptions, Scheduled Payments | Live | |
| Transaction Tokens (card, Konbini, online wallets, Paidy, bank transfer, QR) | Live | |
| Stores, Merchants, Configuration | Live | Except `update()` — see below. |
| Transaction History | Live | Read-only: `GET /transaction_history`. |
| Webhook parsing (`parseWebhookData`) | Live | See [Webhook notes](#webhook-notes) for corner cases carried over verbatim. |
| **`Store::update()`, `Merchant::update()`** | **Permanent throw** | No update endpoint for either resource was ever exposed by the old SDK or the backend — this isn't an engine gap, there is nothing to call. |
| **`Transfer`, `TransferStatusChange`, `Ledger`** (and the `GetTransfers`/`GetLedgers`/`GetStatusChanges` mixins) | **Permanent throw** | The new engine has no Transfers API at all — no controller, no listing, no fetch. `Transfer` webhook events still hydrate (see [Webhook notes](#webhook-notes)); every subsequent call on that object throws. |
| **`BankAccount`** (and the `GetBankAccounts` mixin) | **Permanent throw** | The new engine has no Bank Accounts API at all — no controller, no listing, no fetch, no update. Unlike `Transfer`, the old SDK's webhook events never carried a bank account payload either, so there is no live channel this class still serves; it remains a hydration-capable data class purely for parity with every other ported resource. |
| **`ApplePayPayment` token creation** | **Permanent throw** | Constructing the value object still works; creating a token from it does not — Apple Pay isn't wired into the new engine. |
| **`Charge::qrMerchantToken()`** | **Permanent throw** | Only this one method — `Charge` itself is fully supported. The underlying `/qr` endpoint is deprecated upstream; MPM QR data is available from the token object instead. |

"Permanent throw" means feature-frozen: these will not gain support in a future compat release.
They are reachable only through the native SDK (see [Migrating off the compat layer](#migrating-off-the-compat-layer)),
and the migration tool flags every call site that reaches one so it's a reviewable line instead of a
runtime surprise.

## Behavior deltas

Compat is not a byte-for-byte replay of the old SDK — a small number of documented, deliberate
differences exist.

| Area | Behavior |
|---|---|
| `listTransactions()` | Null-safe on `$from`/`$to` — omitting either (e.g. to filter by status alone) no longer fatals. |
| Card token hydration | `billing`/`three_ds` are nullable — absent on the wire yields `null`, not a `TypeError`. |
| CVV authorization status | `CvvAuthorizationStatus::ERROR()` exists for the backend's `error` status value. |
| `CheckoutInfo` | `supportedCurrencies` is nullable — `null` when the server omits it, not a fatal. |
| Bank-transfer issuer token | `call_method` is optional — `null` when the payload omits it (other payment types are unaffected). |
| Paidy token | `phone_number` hydrates as a plain string, not the nested `{country_code, local_number}` shape. |

Beyond those, several other differences are deliberate:

- **`UnivapayNetworkError` replaces the old `WpOrg\Requests\Exception` retry target.** A genuine
  transport failure (DNS, connection refused, timeout before any response) surfaces from the new
  engine as `ApiException` with `getCode() === 0`. The old SDK's `NetworkRetryHandler` matched on
  `WpOrg\Requests\Exception`, a class that never appears on this transport — so that retry path was
  silently dead. Compat's `NetworkRetryHandler` targets `Errors\UnivapayNetworkError` instead, which
  `Support\ExceptionMapper` raises specifically for this case (not `UnivapayServerError`, which would
  mislabel a network failure as a 5xx). The migration tool flags any consumer code that still
  catches `WpOrg\Requests\Exception` for manual review.
- **10-second timeout, matching what integrators have always experienced.** The old SDK's transport
  (`rmccue/requests`) defaulted to 10s and never exposed the knob. The new engine's own default is
  30s; `Support\Bridge` pins it back to 10 so nothing that depended on that ceiling changes behavior.
- **Retry-safe idempotency.** The old SDK generated one idempotency key per logical call and reused
  it on every retry within that call. The new engine's `IdempotencyCallback` mints a fresh key per
  HTTP request by default — combined with the default retry cascade (rate-limit + network retries,
  up to 4 attempts), a timed-out-but-actually-processed `POST /charges` could create up to 4 real
  charges before this fix. `Support\ApiCaller` generates one key per logical call, outside the retry
  loop, and passes it explicitly on every attempt — exactly what the old SDK did.
- **Error mapping goes through `ApiResponse::isError()`, not a caught exception.** Every generated
  API method's response handler is configured to *return* an error response rather than throw one —
  a 4xx/5xx from `univapay/client-sdk` comes back as a plain, non-throwing `ApiResponse` whose
  `isError()` is `true`, not an exception. `Support\ApiCaller` checks for that on every call and maps
  it via `Support\ExceptionMapper` into the same `Errors\*` hierarchy the old SDK exposed
  (`UnivapayNotFoundError`, `UnivapayRequestError`, etc.) — including the old SDK's own quirk that
  404 responses carry no decoded error body (only 400/401/403 do). A genuine transport failure (no
  HTTP response at all) is the one case that *does* still throw, and is mapped separately into
  `UnivapayNetworkError` above. See `docs/ARCHITECTURE.md` for the full mechanism and why it matters.

## Webhook notes

`UnivapayClient::parseWebhookData()` reproduces the old SDK's dispatch and its corner cases
verbatim — including ones that look like bugs but are pinned, intentional behavior:

- **Transfer events hydrate; everything else about `Transfer` still throws.**
  `transfer_created`/`transfer_updated`/`transfer_finalized` webhook payloads hydrate a real
  `Resources\Transfer` object regardless of the fact that `Transfer` itself is unsupported for
  direct API access (see the [surface matrix](#supported-surface-matrix) above) — the webhook
  channel keeps delivering that data independent of whether this transport engine exposes a
  Transfers API. Any subsequent call on that object — `fetch()`, `update()`, `listLedgers()`,
  `listStatusChanges()` — throws `UnivapayUnsupportedFeatureError`. Parsing the payload does not
  make the resource supported.
- **Three current token event types have no compat enum case.** The live API's `TokenEvent`
  discriminator now includes `token_three_d_s_updated`, `token_cvv_auth_check_updated`, and
  `token_replaced` — additions made after the old SDK's `Enums\WebhookEvent` was last updated, so
  none of the three exist in compat's ported version of that enum either (it mirrors the old SDK,
  not the current spec). A webhook delivery carrying one of these three event types will raise
  `Errors\UnivapayUnknownWebhookEvent`, exactly as any other unrecognized `event` string would. If
  your integration needs these events, handle them via `native()` (see below) instead of
  `parseWebhookData()`.
- **A merchant-level app token receiving a store-scoped event gets `UnivapayInvalidWebhookData`, not
  a clearer error.** `TOKEN_*`, `REFUND_FINISHED`, and `CANCEL_FINISHED` events require a
  store-scoped JWT, exactly as the old SDK's context lookups did; the guard that enforces this fires
  *inside* the same `try` block that a broad `catch` funnels into `UnivapayInvalidWebhookData` — so
  that's what a merchant-JWT client sees, not a more specific "wrong token type" error. This is
  reproduced exactly, not cleaned up, because the old SDK's own behavior here is what any existing
  integration has already coded around.
- **`customs_declaration_finished` has an enum case but no parser — it also maps to
  `UnivapayInvalidWebhookData`.** The event type is recognized (it doesn't raise
  `UnivapayUnknownWebhookEvent`), but there has never been a resource type for it to hydrate into,
  in the old SDK or here.

## Architecture

Requests are built typed, against `univapay/client-sdk`'s own generated models — every field your
code sets goes through the same validation and serialization the native SDK would use. Responses,
by contrast, are hydrated from the raw captured wire body through the old SDK's own ported JSON
schema parsers, not through the generated SDK's typed response models — the only way to guarantee
wire-for-wire parity with what the old SDK's battle-tested parsers already handled (including shapes
the current spec doesn't describe yet). See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the
full request/response diagram and the confinement boundary that keeps raw-body access contained to
a reviewed allowlist of files.

## Migrating off the compat layer

Compat is not meant to live forever. `UnivapayClient::native()` returns the exact
`UnivaPay\UnivapayClientSdkClient` instance this client already built internally to make its own
calls — same auth, base URL, and 10-second timeout as the compat surface, never a second,
separately configured client:

```php
$client = new UnivapayClient($storeAppToken);

// Compat surface -- unchanged.
$charge = $client->createToken($paymentMethod)->createCharge(Money::USD(1000))->awaitResult();

// Native surface -- same engine, same auth, same connection settings.
$native = $client->native();
$chargesApi = $native->getChargesApi();
```

This enables **mixed mode**: migrate call sites file by file, rewriting each one against `native()`'s
typed API while everything not yet touched keeps calling the compat facade exactly as before. Both
paths share one engine, so there is no drift between them during the migration window — a charge
created through `native()` is visible to code still reading it through compat, and vice versa.

A full construct-by-construct migration reference — `Money` → `int` + currency string,
`ChargeStatus::SUCCESSFUL()` identity comparisons → string constants, `$charge->status` → typed
getters, `awaitResult()` → `pollCharge()`, paginated lists → cursor parameters, `parseWebhookData()`
→ typed webhook handler classes, and more, each with a before/after snippet — is tracked in the
portal guide's [Phase 2 section](https://univapay.com/docs/#/http/onboarding-guides/guides/php-sdk-migration#migrating-further-to-the-native-sdk),
not duplicated here.

## Versioning and sunset policy

Compat is feature-frozen as of 1.0: every new API capability lands in `univapay/client-sdk` only,
reachable through `native()`. Compat itself continues to receive bugfixes and follows the engine
SDK's own version bumps, for as long as integrators still depend on the old SDK's surface — there is
no forced end-of-life date. That asymmetry (old surface stays exactly as it is; new capability only
exists on the other side of `native()`) is the intended, gradual pressure to migrate, not a cliff.

## License

MIT.
