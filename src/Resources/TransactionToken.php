<?php

namespace Univapay\Compat\Resources;

use DateInterval;
use DateTime;
use Money\Money;
use UnivaPay\Models\BankTransferTransactionToken;
use UnivaPay\Models\CardTransactionToken;
use UnivaPay\Models\EnableTokenThreeDsRequest;
use UnivaPay\Models\KonbiniTransactionToken;
use UnivaPay\Models\OnlineTransactionToken;
use UnivaPay\Models\PaidyTransactionToken;
use UnivaPay\Models\QrMerchantTransactionToken;
use UnivaPay\Models\QrScanTransactionToken;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\CvvAuthorizationStatus;
use Univapay\Compat\Enums\Field;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\Period;
use Univapay\Compat\Enums\Reason;
use Univapay\Compat\Enums\ThreeDSStatus;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Enums\UsageLimit;
use Univapay\Compat\Errors\UnivapayLogicError;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\PaymentData\CardData;
use Univapay\Compat\Resources\PaymentData\ConvenienceStoreData;
use Univapay\Compat\Resources\PaymentData\OnlineData;
use Univapay\Compat\Resources\PaymentData\PaidyData;
use Univapay\Compat\Resources\PaymentData\QrMerchantData;
use Univapay\Compat\Resources\PaymentData\QrScanData;
use Univapay\Compat\Resources\PaymentMethod\PaymentMethodPatch;
use Univapay\Compat\Resources\PaymentToken\ThreeDSIssuerToken;
use Univapay\Compat\Resources\Subscription\InstallmentPlan;
use Univapay\Compat\Resources\Subscription\ScheduleSettings;
use Univapay\Compat\Resources\Subscription\SubscriptionPlan;
use Univapay\Compat\Support\RequestModelFactory;
use Univapay\Compat\Support\TypedHydrator;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\TransactionToken` (namespace lines + transport plumbing only --
 * public props, guards, and payload-building logic are otherwise verbatim).
 *
 * ## Transport wiring (fetch/update/deactivate/threeDSIssuerToken)
 *
 * `fetchCall()`/`updateCall()` (required by `Resource`) and `deactivate()`/`threeDSIssuerToken()`
 * all follow the same shape: pull the store-scoped `TransactionTokensApi` off this token's own
 * `Bridge` (via `$this->context->bridge()`), build a small closure invoking the one generated
 * controller method needed, and run it through `Support\ApiCaller` with the Bridge's own handler
 * cascade. Old `getIdContext()`'s hand-built `['stores', $this->storeId, 'tokens', $this->id]`
 * path is gone -- the generated controller methods already know their own routes and just take
 * `$storeId`/`$id` as plain arguments. Note this uses the OBJECT's OWN `$storeId` property
 * (parsed from the wire, exactly like old `getIdContext()` did), not `$this->context->storeId`
 * (which only ever reflects the JWT's store, and is null for a merchant-level token list).
 *
 * ## createCharge()/createSubscription(): the reuse seam with Resources\Charge/Subscription
 *
 * Old `TransactionToken::createCharge()`/`createSubscription()` build a raw payload array and
 * `RequesterUtils::executePost()` it, hydrating a `Charge`/`Subscription`. In compat,
 * `Support\RequestModelFactory::chargeCreate()`/`subscriptionCreate()` builds the outbound *typed
 * request model* and `Resource::callAndHydrate()` runs it through `Support\ApiCaller` and hydrates
 * the response into a *different* resource type than `TransactionToken` itself.
 *
 * `Resources\Charge` and `Resources\Subscription` are referenced below by fully-qualified STRING
 * (not a `use` import) passed into `callAndHydrate()`; a class reference passed this way is only
 * resolved when the line actually executes.
 *
 * `UnivapayClient::createCharge()`/`createSubscription()` are themselves just: fetch the token via
 * `getTransactionToken($tokenId)`, then delegate to `$token->createCharge(...)`/
 * `$token->createSubscription(...)` -- i.e. these PUBLIC instance methods already ARE the shared
 * logic the client-level methods reuse (see `Support\Bridge::requireStoreId()`'s doc for the
 * store-token guard that gates client-level `createToken()`/`getTransactionToken()`, which the
 * client's `createCharge()`/`createSubscription()` inherit for free through that
 * `getTransactionToken()` call).
 *
 * ## enableThreeDS()
 *
 * `POST`/`DELETE stores/{storeId}/tokens/{id}/three_ds` enable/disable 3DS on a RECURRING token.
 * The RECURRING-only guard (old-SDK-identical error/message parity) fires before either HTTP call.
 *
 * ## awaitResult(): dual sub-status override
 *
 * See `fetchWithPolling()`'s and `awaitResult()`'s own docs -- `awaitResult()` OVERRIDES the
 * generic `Pollable` trait implementation entirely (not just `fetchWithPolling()`) because this
 * class has no top-level `$status` to key a single transition map off of.
 */
class TransactionToken extends Resource
{
    use Jsonable;
    use Pollable;

    private const POLLABLE_STATUS_THREE_DS = 'threeDS';
    private const POLLABLE_STATUS_CVV_AUTHORIZE = 'cvvAuthorize';

    public $storeId;
    public $email;
    public $active;
    public $paymentType;
    public $mode;
    public $type;
    public $confirmed;
    public $createdOn;
    public $data;
    public $metadata;
    public $usageLimit;
    public $lastUsedOn;
    public $ipAddress;

    /**
     * @param mixed $id
     * @param mixed $storeId
     * @param mixed $email
     * @param mixed $active
     * @param mixed $confirmed
     * @param mixed $data
     * @param mixed $metadata
     * @param mixed $ipAddress
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $storeId,
        $email,
        $active,
        PaymentType $paymentType,
        AppTokenMode $mode,
        TokenType $type,
        $confirmed,
        DateTime $createdOn,
        $data,
        $metadata = null,
        ?UsageLimit $usageLimit = null,
        ?DateTime $lastUsedOn = null,
        $ipAddress = null,
        $context = null
    ) {
        parent::__construct($id, $context);
        $this->email = $email;
        $this->active = $active;
        $this->storeId = $storeId;
        $this->paymentType = $paymentType;
        $this->mode = $mode;
        $this->type = $type;
        $this->confirmed = $confirmed;
        $this->metadata = $metadata;
        $this->createdOn = $createdOn;
        $this->usageLimit = $usageLimit;
        $this->lastUsedOn = $lastUsedOn;
        $this->ipAddress = $ipAddress;
        // The payment data may not be available when retrieving from a list. Triggering a
        // ->fetch() will fix this -- verbatim old-SDK comment/behavior.
        $this->data = $data;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('payment_type', true, FormatterUtils::getTypedEnum(PaymentType::class))
            ->upsert('mode', true, FormatterUtils::getTypedEnum(AppTokenMode::class))
            ->upsert('type', true, FormatterUtils::getTypedEnum(TokenType::class))
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'))
            ->upsert('usage_limit', false, FormatterUtils::getTypedEnum(UsageLimit::class))
            ->upsert('last_used_on', false, FormatterUtils::of('getDateTime'))
            ->upsert('data', false, function ($value, $json) {
                $paymentType = PaymentType::fromValue($json['payment_type']);
                switch ($paymentType) {
                    case PaymentType::CARD():
                    case PaymentType::APPLE_PAY():
                        return CardData::getSchema()->parse($value);
                    case PaymentType::KONBINI():
                        return ConvenienceStoreData::getSchema()->parse($value);
                    case PaymentType::QR_SCAN():
                        return QrScanData::getSchema()->parse($value);
                    case PaymentType::QR_MERCHANT():
                        return QrMerchantData::getSchema()->parse($value);
                    case PaymentType::PAIDY():
                        return PaidyData::getSchema()->parse($value);
                    case PaymentType::ONLINE():
                        return OnlineData::getSchema()->parse($value);
                }
            });
    }

    /**
     * Typed-first hydration entry point for `Support\TypedHydrator`. The generated SDK models
     * this response as a 7-way discriminated union on `payment_type`
     * (`Card`/`Konbini`/`Online`/`BankTransfer`/`Paidy`/`QrScan`/`QrMerchant` TransactionToken --
     * see docs/ARCHITECTURE.md) -- none of the 7 concrete classes share a common parent/interface,
     * so `$typed` is narrowed via `instanceof` against all 7. Every one of them exposes the SAME
     * base-field getters (id/storeId/email/active/mode/type/usageLimit/confirmed/createdOn/
     * updatedOn/lastUsedOn) despite having no shared type to declare that on, so
     * `baseArgsFromTyped()` below calls them via plain duck typing.
     *
     * `metadata` is read from $body (this response's raw decoded body), not the typed
     * `getMetadata()` -- same GenericMetadata-is-always-raw treatment as `Charge`/`Refund`/
     * `Cancel`. `ip_address` is also patched from $body -- a genuine spec gap: none of the 7
     * generated variants (nor a shared base) carry it at all, despite this class's own
     * `initSchema()` reading it (auto-derived from the declared property, optional, identity
     * formatter). `data` is dispatched to the matching `PaymentData\*::hydrateFromTyped()` for 6 of
     * the 7 variants; `bank_transfer` has no compat `PaymentData\*` class at all (a PRE-EXISTING
     * raw-path gap -- this class's own `initSchema()`'s `data` switch has no `BANK_TRANSFER` case
     * either, so raw hydration already always nulls `data` for that variant; typed hydration
     * matches it exactly, not a new gap).
     *
     * Declines (null) when a required base field is missing, or when the payment-type-specific
     * `PaymentData\*::hydrateFromTyped()` call declines -- both fall back to the raw path, which
     * throws/behaves identically for the same malformed response.
     *
     * @param mixed $typed
     * @param array $body
     * @param \Univapay\Compat\Support\CompatContext|null $context
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body, $context)
    {
        $baseArgs = self::baseArgsFromTyped($typed);
        if ($baseArgs === null) {
            return null;
        }
        list($id, $storeId, $email, $active, $mode, $type, $confirmed, $createdOn, $usageLimit, $lastUsedOn)
            = $baseArgs;

        $paymentTypeValue = $typed->getPaymentType();
        if ($paymentTypeValue === null) {
            return null;
        }
        $paymentType = PaymentType::fromValue($paymentTypeValue);

        // 'data' is optional at THIS class's own top level (initSchema()'s 'data' upsert is
        // required=false -- see its own comment: absent when hydrated from a list, fixed by a
        // later fetch()), but every generated variant's getData() has a NON-nullable return type.
        // If the wire genuinely has no 'data' at all, the property is never set and calling
        // getData() throws a TypeError -- caught here and treated as "no typed data", matching the
        // raw path's graceful null instead of forcing the whole token through the raw fallback.
        try {
            $typedData = $typed->getData();
        } catch (\TypeError $e) {
            $typedData = null;
        }

        $dataBody = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
        if ($typedData === null && !array_key_exists('data', $body)) {
            $data = null;
        } else {
            $data = self::dataFromTyped($paymentType, $typedData, $dataBody);
            if ($data === self::DATA_UNMAPPABLE) {
                return null;
            }
        }

        $metadata = array_key_exists('metadata', $body) ? $body['metadata'] : null;
        // Spec gap: the generated model has no ip_address field at all (none of the 7 variants,
        // nor TransactionTokenBase, declare it) despite this class's own auto-derived schema
        // reading it (identity, optional) like every other undeclared-in-initSchema() property.
        $ipAddress = array_key_exists('ip_address', $body) ? $body['ip_address'] : null;

        return new self(
            $id,
            $storeId,
            $email,
            $active,
            $paymentType,
            $mode,
            $type,
            $confirmed,
            $createdOn,
            $data,
            $metadata,
            $usageLimit,
            $lastUsedOn,
            $ipAddress,
            $context
        );
    }

    /** Sentinel: the payment-type-specific data mapping declined -- distinct from a real `null` data. */
    private const DATA_UNMAPPABLE = "\0unmappable\0";

    /**
     * @param mixed $typed One of the 7 union classes -- duck-typed, see hydrateFromTyped()'s doc.
     * @return array|null [id, storeId, email, active, mode, type, confirmed, createdOn, usageLimit,
     *         lastUsedOn], or null if `mode`/`type`/`created_on` (required=true in this class's own
     *         schema) is missing.
     */
    private static function baseArgsFromTyped($typed)
    {
        $mode = $typed->getMode();
        $type = $typed->getType();
        $createdOn = $typed->getCreatedOn();
        if ($mode === null || $type === null || $createdOn === null) {
            return null;
        }
        return [
            $typed->getId(),
            $typed->getStoreId(),
            $typed->getEmail(),
            $typed->getActive(),
            AppTokenMode::fromValue($mode),
            TokenType::fromValue($type),
            $typed->getConfirmed(),
            $createdOn,
            UsageLimit::fromValue($typed->getUsageLimit()),
            $typed->getLastUsedOn(),
        ];
    }

    /**
     * @param PaymentType $paymentType
     * @param mixed $typedData The union variant's own `getData()` result.
     * @param array $dataBody Raw `data` sub-object, for the one or two fields a variant's typed
     *        data model can't carry (see each `PaymentData\*::hydrateFromTyped()`'s own doc).
     * @return mixed A hydrated `PaymentData\*` instance, null (the `bank_transfer` no-compat-class
     *         case -- a real, intentional null, matching the raw path exactly), or
     *         `self::DATA_UNMAPPABLE` if the variant-specific hydration declined.
     */
    private static function dataFromTyped(PaymentType $paymentType, $typedData, array $dataBody)
    {
        switch ($paymentType) {
            case PaymentType::CARD():
            case PaymentType::APPLE_PAY():
                $data = CardData::hydrateFromTyped($typedData, $dataBody);
                break;
            case PaymentType::KONBINI():
                $data = ConvenienceStoreData::hydrateFromTyped($typedData, $dataBody);
                break;
            case PaymentType::QR_SCAN():
                $data = QrScanData::hydrateFromTyped($typedData, $dataBody);
                break;
            case PaymentType::QR_MERCHANT():
                $data = QrMerchantData::hydrateFromTyped($typedData, $dataBody);
                break;
            case PaymentType::PAIDY():
                $data = PaidyData::hydrateFromTyped($typedData, $dataBody);
                break;
            case PaymentType::ONLINE():
                $data = OnlineData::hydrateFromTyped($typedData, $dataBody);
                break;
            default:
                // PaymentType::BANK_TRANSFER(), or any future value this switch (and the raw
                // path's identical one in initSchema()) doesn't know yet -- matches the raw path's
                // implicit-null fallthrough exactly.
                return null;
        }
        return $data === null ? self::DATA_UNMAPPABLE : $data;
    }

    // --- Resource wiring (fetch/update) --------------------------------------------------------

    protected function fetchCall()
    {
        $bridge = $this->context->bridge();
        $tokens = $bridge->tokens();
        return $bridge->caller()->callTyped(
            function () use ($tokens) {
                return $tokens->getTransactionToken($this->storeId, $this->id);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/tokens/{$this->id}"
        );
    }

    /**
     * @param PaymentMethodPatch $updates
     */
    protected function updateCall($updates)
    {
        $request = RequestModelFactory::tokenPatch($updates);
        $bridge = $this->context->bridge();
        $tokens = $bridge->tokens();
        return $bridge->caller()->callTyped(
            function ($idempotencyKey) use ($tokens, $request) {
                return $tokens->updateTransactionToken($this->storeId, $this->id, $idempotencyKey, $request);
            },
            $bridge->handlers(),
            "PATCH /stores/{$this->storeId}/tokens/{$this->id}"
        );
    }

    protected function pollableStatuses()
    {
        return [
            self::POLLABLE_STATUS_THREE_DS => [
                (string) ThreeDSStatus::PENDING() => array_diff(
                    ThreeDSStatus::findValues(),
                    [ThreeDSStatus::PENDING()]
                ),
                (string) ThreeDSStatus::AWAITING() =>
                    [
                        ThreeDSStatus::SUCCESSFUL(),
                        ThreeDSStatus::FAILED(),
                        ThreeDSStatus::ERROR()
                    ]
            ],
            self::POLLABLE_STATUS_CVV_AUTHORIZE => [
                (string) CvvAuthorizationStatus::PENDING() => array_diff(
                    CvvAuthorizationStatus::findValues(),
                    [CvvAuthorizationStatus::PENDING()]
                )
            ]
        ];
    }

    /**
     * `GET /stores/{storeId}/tokens/{id}?polling=true` -- one held GET per attempt,
     * exactly like every other `Pollable` resource's `fetchWithPolling()`. No client-side
     * `sleep()`: all of `awaitResult()`'s wall-clock wait comes from the server HOLDING the GET
     * open until a status transition or its own (~3s) timeout, matching old-SDK parity.
     *
     * @return static
     */
    protected function fetchWithPolling()
    {
        $bridge = $this->context->bridge();
        $tokens = $bridge->tokens();
        $result = $bridge->caller()->callTyped(
            function () use ($tokens) {
                return $tokens->getTransactionToken($this->storeId, $this->id, true);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/tokens/{$this->id}?polling=true"
        );
        return $this->resolveHydration($result);
    }

    /**
     * OVERRIDES `Pollable::awaitResult()`. The generic trait implementation compares a single
     * top-level `$this->status` against `$response->status` -- `TransactionToken` has NO top-level
     * `$status` property at all (see class doc). Old `TransactionToken::awaitResult()` instead
     * checks TWO independent nested sub-statuses on `$this->data`/`$response->data`:
     * `threeDS->status` and `cvvAuthorize->status` (both only ever present on `PaymentData\CardData`
     * -- every other payment type's `data` union member has neither property, which
     * `subStatus()` below treats as "nothing to track for this sub-status", not an error).
     * Polling continues attempting fresh held GETs only while at least one of the two tracked
     * sub-statuses has NOT yet transitioned out of its PENDING/AWAITING starting point (per
     * `pollableStatuses()`'s transition maps) and $retry attempts remain -- same "no client-side
     * sleep, one held GET per attempt" contract `Pollable::awaitResult()` itself documents, just
     * evaluated per sub-status instead of once.
     *
     * @param int $retry Maximum number of ADDITIONAL held-GET attempts beyond the first.
     * @return static
     */
    public function awaitResult($retry = 0)
    {
        $pollableStatuses = $this->pollableStatuses();
        $response = $this->fetchWithPolling();
        $retryCount = 0;
        while (
            $retryCount < $retry &&
            !self::subStatusesTransitioned($this->data, $response->data, $pollableStatuses)
        ) {
            $retryCount++;
            $response = $this->fetchWithPolling();
        }
        return $response;
    }

    /**
     * @param mixed $originalData This token's OWN `$data` (the polling starting point, never the
     *        latest `$response->data` -- mirrors `Pollable::awaitResult()`'s use of `$this->status`,
     *        not the previous response's, to key the transition map).
     * @param mixed $responseData The most recent held-GET response's `$data`.
     * @param array $pollableStatuses `pollableStatuses()`'s own [threeDS => [...], cvvAuthorize =>
     *        [...]] map.
     */
    private static function subStatusesTransitioned($originalData, $responseData, array $pollableStatuses): bool
    {
        $threeDsTransitioned = self::subStatusTransitioned(
            self::subStatus($originalData, 'threeDS'),
            self::subStatus($responseData, 'threeDS'),
            $pollableStatuses[self::POLLABLE_STATUS_THREE_DS]
        );
        $cvvAuthorizeTransitioned = self::subStatusTransitioned(
            self::subStatus($originalData, 'cvvAuthorize'),
            self::subStatus($responseData, 'cvvAuthorize'),
            $pollableStatuses[self::POLLABLE_STATUS_CVV_AUTHORIZE]
        );
        return $threeDsTransitioned && $cvvAuthorizeTransitioned;
    }

    /**
     * @param mixed $data A payment-data union member (e.g. `PaymentData\CardData`) or null.
     * @param string $property `'threeDS'` or `'cvvAuthorize'`.
     * @return mixed The sub-status TypedEnum instance, or null if this payment type's `data` has
     *         no such property at all (every non-CARD payment type) or the property itself is
     *         unset/null.
     */
    private static function subStatus($data, string $property)
    {
        if (!isset($data->$property) || !isset($data->$property->status)) {
            return null;
        }
        return $data->$property->status;
    }

    /**
     * @param mixed $originalStatus The sub-status `$this->data` started at, or null if this
     *        payment type/token has no such sub-status to track at all.
     * @param mixed $responseStatus The sub-status on the latest held-GET response.
     * @param array $pollableMap `pollableStatuses()`'s per-sub-status map: (string) starting
     *        status => array of statuses that count as "transitioned away from it".
     */
    private static function subStatusTransitioned($originalStatus, $responseStatus, array $pollableMap): bool
    {
        if ($originalStatus === null) {
            // Nothing to track for this sub-status on this token -- trivially "transitioned",
            // mirroring Pollable::awaitResult()'s own array_key_exists() gate (a starting status
            // absent from the map never blocks the loop).
            return true;
        }
        $key = (string) $originalStatus;
        if (!array_key_exists($key, $pollableMap)) {
            return true;
        }
        return in_array($responseStatus, $pollableMap[$key], true);
    }

    // --- patch/deactivate/enableThreeDS/threeDSIssuerToken -------------------------------------

    /**
     * Old parity: PATCH's own response does not always carry the full payment `data` back, so old
     * `patch()` re-`fetch()`es after the update instead of trusting the PATCH response directly.
     * `update()` (inherited from `Resource`) performs the PATCH itself via `updateCall()` above;
     * calling `->fetch()` on ITS result issues the follow-up GET, exactly matching old
     * `return $this->update($paymentPatch)->fetch();`.
     *
     * @return static
     */
    public function patch(PaymentMethodPatch $paymentPatch)
    {
        return $this->update($paymentPatch)->fetch();
    }

    /**
     * @return bool Always `true` on success -- `Support\ApiCaller` decodes the 204's empty body to
     *         `true` (see its class doc), matching old `RequesterUtils::executeDelete()`'s bare
     *         `true` return.
     */
    public function deactivate()
    {
        $bridge = $this->context->bridge();
        $tokens = $bridge->tokens();
        return $bridge->caller()->call(
            function () use ($tokens) {
                return $tokens->deleteTransactionToken($this->storeId, $this->id);
            },
            $bridge->handlers(),
            "DELETE /stores/{$this->storeId}/tokens/{$this->id}"
        );
    }

    /**
     * Enable/disable 3DS for a RECURRING transaction token
     * (`POST`/`DELETE /stores/{storeId}/tokens/{id}/three_ds`).
     *
     * The RECURRING-only guard below is old-SDK-identical (same `Reason`, same error class) and
     * fires before either HTTP call. Return-value shape follows the two generated endpoints'
     * actual response shapes, not a single uniform contract: `TransactionTokensApi::
     * enableTokenThreeDs()` responds with the full (typed-union) transaction token body, so
     * enabling hydrates and returns a NEW `TransactionToken` instance (`patch()`/`fetch()`
     * parity -- never mutates `$this`); `disableTokenThreeDs()` has no response type set at all
     * (empty body, matching `deleteTransactionToken()`'s shape) and returns bare `true`.
     *
     * @param bool $enabled
     * @param string|null $redirectEndpoint Optional redirect endpoint when enabling 3DS.
     * @return static|bool A NEW hydrated `TransactionToken` when enabling; `true` when disabling.
     * @throws UnivapayLogicError If this token's type is not RECURRING.
     */
    public function enableThreeDS($enabled, $redirectEndpoint = null)
    {
        if ($this->type !== TokenType::RECURRING()) {
            throw new UnivapayLogicError(Reason::TRANSACTION_TOKEN_IS_NOT_RECURRING());
        }

        $bridge = $this->context->bridge();
        $tokens = $bridge->tokens();

        if ($enabled) {
            $request = new EnableTokenThreeDsRequest();
            if ($redirectEndpoint !== null) {
                $request->setRedirectEndpoint($redirectEndpoint);
            }
            $result = $bridge->caller()->callTyped(
                function ($idempotencyKey) use ($tokens, $request) {
                    return $tokens->enableTokenThreeDs($this->storeId, $this->id, $idempotencyKey, $request);
                },
                $bridge->handlers(),
                "POST /stores/{$this->storeId}/tokens/{$this->id}/three_ds"
            );
            return $this->resolveHydration($result);
        }

        $bridge->caller()->call(
            function () use ($tokens) {
                return $tokens->disableTokenThreeDs($this->storeId, $this->id);
            },
            $bridge->handlers(),
            "DELETE /stores/{$this->storeId}/tokens/{$this->id}/three_ds"
        );
        return true;
    }

    // --- createCharge/createSubscription (guards ported verbatim) ------------------------------

    private function validateCreateCharge()
    {
        if ($this->type === TokenType::SUBSCRIPTION()) {
            throw new UnivapayLogicError(Reason::NON_SUBSCRIPTION_PAYMENT());
        }
        $this->validateCVV();
    }

    /**
     * @param mixed $capture
     * @param mixed $onlyDirectCurrency
     */
    public function createCharge(
        Money $money,
        $capture = null,
        ?DateTime $captureAt = null,
        ?array $metadata = null,
        $onlyDirectCurrency = null,
        ?Redirect $redirect = null,
        ?PaymentThreeDS $threeDS = null
    ) {
        $this->validateCreateCharge();
        $this->validateCapture($capture, $captureAt);

        $request = RequestModelFactory::chargeCreate(
            $this->id,
            $money,
            $capture,
            $captureAt,
            $metadata,
            $onlyDirectCurrency,
            $redirect,
            $threeDS
        );

        $bridge = $this->context->bridge();
        $chargesApi = $bridge->charges();

        // Referenced by FQCN string rather than a `use` import -- see class doc
        // "createCharge()/createSubscription(): the reuse seam with Resources\Charge/Subscription".
        return $this->callAndHydrate(
            '\Univapay\Compat\Resources\Charge',
            function ($idempotencyKey) use ($chargesApi, $request) {
                return $chargesApi->createCharge($idempotencyKey, $request);
            },
            'POST /charges',
            $this->context->withStoreId($this->storeId)
        );
    }

    private function validateCreateSubscription(
        Money $money,
        ?Period $period = null,
        ?DateInterval $cyclicalPeriod = null,
        ?Money $initialAmount = null,
        ?ScheduleSettings $scheduleSettings = null
    ) {
        if ($this->type === TokenType::ONE_TIME()) {
            throw new UnivapayLogicError(Reason::NOT_SUBSCRIPTION_PAYMENT());
        }
        if (!isset($period) && !isset($cyclicalPeriod)) {
            throw new UnivapayValidationError(Field::PERIOD(), Reason::PERIOD_OR_CYCLICAL_PERIOD_MUST_BE_SET());
        }
        if (!$money->isPositive()) {
            throw new UnivapayValidationError(Field::AMOUNT(), Reason::INVALID_AMOUNT());
        }
        if (isset($initialAmount) && ($initialAmount->isNegative() || !$initialAmount->isSameCurrency($money))) {
            throw new UnivapayValidationError(Field::INITIAL_AMOUNT(), Reason::INVALID_AMOUNT());
        }
        if (
            isset($scheduleSettings) &&
            $scheduleSettings->preserveEndOfMonth === true &&
            Period::MONTHLY() !== $period
        ) {
            throw new UnivapayValidationError(Field::PRESERVE_END_OF_MONTH(), Reason::MUST_BE_MONTH_BASE_TO_SET());
        }
        $this->validateCVV();
    }

    /**
     * @param mixed $onlyDirectCurrency
     * @param mixed $firstChargeAuthorizationOnly
     */
    public function createSubscription(
        Money $money,
        ?Period $period = null,
        ?Money $initialAmount = null,
        ?ScheduleSettings $scheduleSettings = null,
        ?SubscriptionPlan $subscriptionPlan = null,
        ?InstallmentPlan $installmentPlan = null,
        ?array $metadata = null,
        $onlyDirectCurrency = null,
        $firstChargeAuthorizationOnly = null,
        ?DateInterval $firstChargeCaptureAfter = null,
        ?DateInterval $cyclicalPeriod = null,
        ?PaymentThreeDS $threeDS = null
    ) {
        $this->validateCreateSubscription($money, $period, $cyclicalPeriod, $initialAmount, $scheduleSettings);
        $this->validateCapture($firstChargeAuthorizationOnly, null, $firstChargeCaptureAfter);

        $request = RequestModelFactory::subscriptionCreate(
            $this->id,
            $money,
            $period,
            $initialAmount,
            $scheduleSettings,
            $subscriptionPlan,
            $installmentPlan,
            $metadata,
            $onlyDirectCurrency,
            $firstChargeAuthorizationOnly,
            $firstChargeCaptureAfter,
            $cyclicalPeriod,
            $threeDS
        );

        $bridge = $this->context->bridge();
        $subscriptionsApi = $bridge->subscriptions();

        // Referenced by FQCN string rather than a `use` import -- see class doc
        // "createCharge()/createSubscription(): the reuse seam with Resources\Charge/Subscription".
        return $this->callAndHydrate(
            '\Univapay\Compat\Resources\Subscription',
            function ($idempotencyKey) use ($subscriptionsApi, $request) {
                return $subscriptionsApi->createSubscription($idempotencyKey, $request);
            },
            'POST /subscriptions',
            $this->context->withStoreId($this->storeId)
        );
    }

    private function validateCVV()
    {
        if (
            $this->paymentType === PaymentType::CARD() &&
            $this->data->cvvAuthorize->enabled &&
            $this->data->cvvAuthorize->status !== CvvAuthorizationStatus::CURRENT()
        ) {
            throw new UnivapayLogicError(Reason::CVV_AUTHORIZATION_REQUIRED());
        }
    }

    /**
     * @param mixed $capture
     */
    private function validateCapture(
        $capture = null,
        ?DateTime $captureAtAbsolute = null,
        ?DateInterval $captureAtRelative = null
    ) {
        // $captureAtAbsolute is computed but never read again below -- verbatim old-SDK quirk
        // (scratchpad/univapay-php-sdk/src/Univapay/Resources/TransactionToken.php's own
        // validateCapture()), ported as-is rather than "fixed".
        if (isset($captureAtRelative)) {
            $captureAtAbsolute = date_create()->add($captureAtRelative);
        }
        if (isset($capture)) {
            if (
                $this->paymentType !== PaymentType::CARD() &&
                $this->paymentType !== PaymentType::APPLE_PAY() &&
                $this->paymentType !== PaymentType::PAIDY()
            ) {
                throw new UnivapayLogicError(Reason::CAPTURE_ONLY_FOR_CARD_PAYMENT());
            }
        }
    }

    // --- 3DS issuer token -----------------------------------------------------------------------

    public function threeDSIssuerToken()
    {
        $bridge = $this->context->bridge();
        $tokens = $bridge->tokens();
        $result = $bridge->caller()->callTyped(
            function () use ($tokens) {
                return $tokens->getTokenThreeDsIssuerToken($this->storeId, $this->id);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/tokens/{$this->id}/three_ds/issuer_token"
        );
        return TypedHydrator::resolve(ThreeDSIssuerToken::class, $result, $this->context);
    }
}
