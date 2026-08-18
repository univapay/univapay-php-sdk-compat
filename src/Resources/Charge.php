<?php

namespace Univapay\Compat\Resources;

use DateTime;
use Money\Currency;
use Money\Money;
use UnivaPay\Models\Charge as GeneratedCharge;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Enums\RefundReason;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\Mixins\GetCancels;
use Univapay\Compat\Resources\Mixins\GetRefunds;
use Univapay\Compat\Resources\PaymentToken\OnlineToken;
use Univapay\Compat\Resources\PaymentToken\ThreeDSIssuerToken;
use Univapay\Compat\Support\ListDispatcher;
use Univapay\Compat\Support\RequestModelFactory;
use Univapay\Compat\Support\TypedHydrator;
use Univapay\Compat\Support\TypedResult;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\Charge` (namespace lines + transport plumbing only -- public
 * props, guards, and payload-building logic are otherwise verbatim, mirroring `TransactionToken`'s
 * porting style). Property order (storeId .. threeDS) already matches the old constructor;
 * see class doc convention established there for why this matters (`Utility\Json\JsonSchema::
 * fromClass()` derives schema paths from declared property ORDER, which the reflection-based
 * `parse()` then feeds back into the constructor positionally).
 *
 * ## Transport wiring
 *
 * `fetchCall()`/`updateCall()` (required by `Resource`) and every HTTP-touching method below pull
 * the store-scoped generated controller off this charge's own `Bridge` (via
 * `$this->context->bridge()`), build a small closure invoking the one generated controller method
 * needed, and run it through `Support\ApiCaller` with the Bridge's own handler cascade -- same
 * shape as `TransactionToken`'s wiring.
 *
 * `patch(array $metadata)` deliberately reuses the inherited `Resource::update()` (which itself
 * calls `updateCall()` through `Support\ApiCaller` and hydrates a NEW `Charge` from the response)
 * rather than duplicating that flow via `callAndHydrate()`: `Resource`'s own class doc draws
 * exactly this line -- `fetch()`/`update()` cover "same resource type, own context" (patch is
 * that), `callAndHydrate()` covers "different resource type" (createRefund/cancel below, which
 * hydrate `Refund`/`Cancel`, are that).
 *
 * `capture(?Money)`'s null case sends no request body at all: the generated
 * `ChargesApi::captureCharge()`'s own `$body` parameter is optional, so passing `null` here omits
 * the body entirely rather than substituting `$this->requestedAmount` as a stand-in (which would
 * silently change the captured amount for multi-currency captures or captures for a different
 * amount than requested). Non-null captures work via `RequestModelFactory::chargeCapture()`.
 *
 * `qrMerchantToken()` is permanently unsupported: the charge-level `/qr` endpoint (MPM QR payload
 * lookup) is deprecated upstream with no real usage -- this throws unconditionally.
 */
class Charge extends Resource
{
    use Jsonable;
    use Pollable;
    use GetCancels, GetRefunds {
        GetCancels::validate insteadof GetRefunds;
    }

    public $storeId;
    public $transactionTokenId;
    public $transactionTokenType;
    public $subscriptionId;
    public $requestedCurrency;
    public $requestedAmount;
    public $requestedAmountFormatted;
    public $status;
    public $mode;
    public $createdOn;
    public $chargedCurrency;
    public $chargedAmount;
    public $chargedAmountFormatted;
    public $onlyDirectCurrency;
    public $captureAt;
    public $error;
    public $metadata;
    public $redirect;
    public $threeDS;

    /**
     * @param mixed $id
     * @param mixed $storeId
     * @param mixed $transactionTokenId
     * @param mixed $subscriptionId
     * @param mixed $requestedAmountFormatted
     * @param mixed $chargedAmountFormatted
     * @param mixed $onlyDirectCurrency
     * @param mixed $error
     * @param mixed $metadata
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $storeId,
        $transactionTokenId,
        TokenType $transactionTokenType,
        $subscriptionId,
        Currency $requestedCurrency,
        Money $requestedAmount,
        $requestedAmountFormatted,
        ChargeStatus $status,
        AppTokenMode $mode,
        DateTime $createdOn,
        ?Currency $chargedCurrency = null,
        ?Money $chargedAmount = null,
        $chargedAmountFormatted = null,
        $onlyDirectCurrency = null,
        ?DateTime $captureAt = null,
        $error = null,
        $metadata = null,
        ?Redirect $redirect = null,
        ?PaymentThreeDS $threeDS = null,
        $context = null
    ) {
        parent::__construct($id, $context);
        $this->storeId = $storeId;
        $this->transactionTokenId = $transactionTokenId;
        $this->transactionTokenType = $transactionTokenType;
        $this->subscriptionId = $subscriptionId;
        $this->requestedCurrency = $requestedCurrency;
        $this->requestedAmount = $requestedAmount;
        $this->requestedAmountFormatted = $requestedAmountFormatted;
        $this->chargedCurrency = $chargedCurrency;
        $this->chargedAmount = $chargedAmount;
        $this->chargedAmountFormatted = $chargedAmountFormatted;
        $this->onlyDirectCurrency = $onlyDirectCurrency;
        $this->captureAt = $captureAt;
        $this->status = $status;
        $this->error = $error;
        $this->metadata = $metadata;
        $this->mode = $mode;
        $this->redirect = $redirect;
        $this->createdOn = $createdOn;
        $this->threeDS = $threeDS;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('transaction_token_type', true, FormatterUtils::getTypedEnum(TokenType::class))
            ->upsert('requested_currency', true, FormatterUtils::of('getCurrency'))
            ->upsert('requested_amount', true, FormatterUtils::getMoney('requested_currency'))
            ->upsert('charged_currency', false, FormatterUtils::of('getCurrency'))
            ->upsert('charged_amount', false, FormatterUtils::getMoney('charged_currency'))
            ->upsert('capture_at', false, FormatterUtils::of('getDateTime'))
            ->upsert('status', true, FormatterUtils::getTypedEnum(ChargeStatus::class))
            ->upsert('mode', true, FormatterUtils::getTypedEnum(AppTokenMode::class))
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'))
            ->upsert('redirect', false, Redirect::getSchema()->getParser())
            ->upsert('three_ds', false, PaymentThreeDS::getSchema()->getParser());
    }

    /**
     * Typed-first hydration entry point for `Support\TypedHydrator`. Builds a `Charge` from the
     * generated SDK's own `UnivaPay\Models\Charge`, applying the same Money/TypedEnum/DateTime
     * conversions `initSchema()`'s formatters apply on the raw path -- `getRequestedCurrency()`/
     * `getCreatedOn()`/etc. are already the right shape (a currency code string, a real
     * `\DateTime`), so this is a direct getter-to-constructor-arg mapping for every field except:
     *
     * - `error`/`metadata`: read from $body (this same response's raw decoded body) instead of the
     *   typed `PaymentError`/`GenericMetadata` models -- compat has always stored these as the raw
     *   decoded value verbatim, never through a `Jsonable` hydration step (see
     *   docs/ARCHITECTURE.md's GenericMetadata note).
     * - `three_ds`: the generated `ChargeThreeDs` response model only carries
     *   `redirect_endpoint`/`mode` -- no MPI fields (`authentication_value`, `eci`,
     *   `ds_transaction_id`, `server_transaction_id`, `message_version`, `transaction_status`) and
     *   no `redirect_id` (see `PaymentThreeDS`'s own class doc for why the generated model can't
     *   express these -- a genuine spec gap, not an oversight here). Parsed from $body's own
     *   `three_ds` sub-object via `PaymentThreeDS`'s existing raw parser instead, so nothing the
     *   raw path reads is ever silently dropped.
     *
     * Declines (returns null, letting `TypedHydrator` fall back to the raw path) when $typed isn't
     * a `UnivaPay\Models\Charge`, or when a field the raw schema marks `required` is missing --
     * the raw path would throw `Utility\Json\RequiredValueNotFoundException` for that same
     * response, and this must not silently paper over it with a null.
     *
     * @param mixed $typed
     * @param array $body
     * @param \Univapay\Compat\Support\CompatContext|null $context
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body, $context)
    {
        if (!($typed instanceof GeneratedCharge)) {
            return null;
        }
        if (
            $typed->getTransactionTokenType() === null
            || $typed->getRequestedCurrency() === null
            || $typed->getRequestedAmount() === null
            || $typed->getStatus() === null
            || $typed->getMode() === null
            || $typed->getCreatedOn() === null
        ) {
            return null;
        }

        $requestedCurrency = new Currency($typed->getRequestedCurrency());
        $chargedCurrencyValue = $typed->getChargedCurrency();
        $chargedCurrency = $chargedCurrencyValue !== null ? new Currency($chargedCurrencyValue) : null;
        $chargedAmount = $chargedCurrency !== null && $typed->getChargedAmount() !== null
            ? new Money($typed->getChargedAmount(), $chargedCurrency)
            : null;
        $redirectTyped = $typed->getRedirect();
        $redirect = $redirectTyped !== null
            ? new Redirect($redirectTyped->getEndpoint(), $redirectTyped->getRedirectId())
            : null;

        return new self(
            $typed->getId(),
            $typed->getStoreId(),
            $typed->getTransactionTokenId(),
            TokenType::fromValue($typed->getTransactionTokenType()),
            $typed->getSubscriptionId(),
            $requestedCurrency,
            new Money($typed->getRequestedAmount(), $requestedCurrency),
            $typed->getRequestedAmountFormatted(),
            ChargeStatus::fromValue($typed->getStatus()),
            AppTokenMode::fromValue($typed->getMode()),
            $typed->getCreatedOn(),
            $chargedCurrency,
            $chargedAmount,
            $typed->getChargedAmountFormatted(),
            $typed->getOnlyDirectCurrency(),
            $typed->getCaptureAt(),
            array_key_exists('error', $body) ? $body['error'] : null,
            array_key_exists('metadata', $body) ? $body['metadata'] : null,
            $redirect,
            self::threeDSFromRawBody($body),
            $context
        );
    }

    /**
     * @param array $body
     * @return PaymentThreeDS|null
     */
    private static function threeDSFromRawBody(array $body)
    {
        if (!array_key_exists('three_ds', $body) || $body['three_ds'] === null) {
            return null;
        }
        return PaymentThreeDS::getSchema()->parse($body['three_ds']);
    }

    protected function pollableStatuses()
    {
        return [
            (string) ChargeStatus::PENDING() => array_diff(ChargeStatus::findValues(), [ChargeStatus::PENDING()]),
            (string) ChargeStatus::AUTHORIZED() => [
                ChargeStatus::SUCCESSFUL(), ChargeStatus::FAILED(), ChargeStatus::ERROR(), ChargeStatus::CANCELED()
            ],
            (string) ChargeStatus::AWAITING() => [
                ChargeStatus::AUTHORIZED(),
                ChargeStatus::SUCCESSFUL(),
                ChargeStatus::FAILED(),
                ChargeStatus::ERROR(),
                ChargeStatus::CANCELED()
            ]
        ];
    }

    // --- Resource wiring (fetch/update/awaitResult) ----------------------------------------------

    protected function fetchCall()
    {
        $bridge = $this->context->bridge();
        $charges = $bridge->charges();
        return $bridge->caller()->callTyped(
            function () use ($charges) {
                return $charges->getCharge($this->storeId, $this->id);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/charges/{$this->id}"
        );
    }

    protected function updateCall($updates)
    {
        $request = RequestModelFactory::chargeUpdate($updates);
        $bridge = $this->context->bridge();
        $charges = $bridge->charges();
        return $bridge->caller()->callTyped(
            function ($idempotencyKey) use ($charges, $request) {
                return $charges->updateCharge($this->storeId, $this->id, $idempotencyKey, $request);
            },
            $bridge->handlers(),
            "PATCH /stores/{$this->storeId}/charges/{$this->id}"
        );
    }

    /**
     * @return static
     */
    protected function fetchWithPolling()
    {
        $bridge = $this->context->bridge();
        $charges = $bridge->charges();
        $result = $bridge->caller()->callTyped(
            function () use ($charges) {
                return $charges->getCharge($this->storeId, $this->id, true);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/charges/{$this->id}?polling=true"
        );
        return $this->resolveHydration($result);
    }

    /**
     * @return static
     */
    public function patch(array $metadata)
    {
        return $this->update(['metadata' => $metadata]);
    }

    // --- createRefund/capture/cancel -------------------------------------------------------------

    /**
     * @return Refund A NEW hydrated instance -- old-SDK parity (`RequesterUtils::executePost()`
     *         never mutated `$this`).
     */
    public function createRefund(
        Money $money,
        ?RefundReason $reason = null,
        $message = null,
        ?array $metadata = null
    ) {
        // CHARGEBACK guard lives inside RequestModelFactory::refundCreate() -- verbatim old-SDK
        // check, not duplicated here.
        $request = RequestModelFactory::refundCreate($money, $reason, $message, $metadata);
        $bridge = $this->context->bridge();
        $refunds = $bridge->refunds();
        return $this->callAndHydrate(
            '\Univapay\Compat\Resources\Refund',
            function ($idempotencyKey) use ($refunds, $request) {
                return $refunds->createRefund($this->storeId, $this->id, $request, $idempotencyKey);
            },
            "POST /stores/{$this->storeId}/charges/{$this->id}/refunds"
        );
    }

    /**
     * Old `capture(?Money $money = null)` sent NO body at all when $money was null (server
     * captures the outstanding authorized amount) and always returned bare `true` regardless --
     * old `RequesterUtils::executePost(null, ...)` never hydrates anything (see its source: a
     * null $parser short-circuits straight to the raw response). That same `true` return is pinned
     * here regardless of whether `$money` is null.
     *
     * The generated `ChargesApi::captureCharge()`'s own `$body` parameter is optional
     * (`?ChargeCaptureRequest $body = null` -- verified against `sdk/php/src/Apis/ChargesApi.php`;
     * its own doc: "if omitted entirely, the full outstanding authorized amount ... is captured"),
     * so the null case sends NO body at all, matching the old wire exactly -- no
     * `$this->requestedAmount` substitution needed.
     *
     * @return bool Always `true` on success.
     */
    public function capture(?Money $money = null)
    {
        $request = $money !== null ? RequestModelFactory::chargeCapture($money) : null;
        $bridge = $this->context->bridge();
        $charges = $bridge->charges();
        $bridge->caller()->call(
            function ($idempotencyKey) use ($charges, $request) {
                return $charges->captureCharge($this->storeId, $this->id, $idempotencyKey, $request);
            },
            $bridge->handlers(),
            "POST /stores/{$this->storeId}/charges/{$this->id}/capture"
        );
        return true;
    }

    /**
     * @return Cancel A NEW hydrated instance.
     */
    public function cancel(?array $metadata = null)
    {
        $request = RequestModelFactory::cancelCreate($metadata);
        $bridge = $this->context->bridge();
        $cancels = $bridge->cancels();
        return $this->callAndHydrate(
            '\Univapay\Compat\Resources\Cancel',
            function ($idempotencyKey) use ($cancels, $request) {
                return $cancels->createCancel($this->storeId, $this->id, $idempotencyKey, $request);
            },
            "POST /stores/{$this->storeId}/charges/{$this->id}/cancels"
        );
    }

    /**
     * Deprecated upstream `/qr` (charge-level QR merchant token) endpoint, permanently unsupported.
     * The MPM QR code payload now comes from the transaction token's own `data.qr_image_url`
     * instead -- despite the field's name, that payload is not guaranteed to be a URL (some brands
     * return an opaque numeric code with no URL structure; see the spec's
     * `TokenResponseQrMerchantData.qr_image_url` description). `PaymentData\QrMerchantData` (this
     * compat layer's hydration target for that field's sibling `brand` property) does not port
     * `qr_image_url` at all -- a verbatim old-SDK gap.
     *
     * @throws UnivapayUnsupportedFeatureError Always.
     */
    public function qrMerchantToken()
    {
        throw new UnivapayUnsupportedFeatureError(
            'Charge::qrMerchantToken() (deprecated /qr endpoint, no real usage -- see the QR merchant '
            . "token's own data.qr_image_url instead)"
        );
    }

    public function onlineToken()
    {
        $bridge = $this->context->bridge();
        $charges = $bridge->charges();
        $result = $bridge->caller()->callTyped(
            function () use ($charges) {
                return $charges->getChargeIssuerToken($this->storeId, $this->id);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/charges/{$this->id}/issuer_token"
        );
        return TypedHydrator::resolve(OnlineToken::class, $result, $this->context);
    }

    public function threeDSIssuerToken()
    {
        $bridge = $this->context->bridge();
        $charges = $bridge->charges();
        $result = $bridge->caller()->callTyped(
            function () use ($charges) {
                return $charges->getChargeThreeDsIssuerToken($this->storeId, $this->id);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/charges/{$this->id}/three_ds/issuer_token"
        );
        return TypedHydrator::resolve(ThreeDSIssuerToken::class, $result, $this->context);
    }

    // --- GetRefunds/GetCancels mixin hooks --------------------------------------------------------

    protected function listRefundsPage(array $query)
    {
        $bridge = $this->context->bridge();
        return ListDispatcher::listRefunds(
            $bridge,
            $this->storeId,
            $this->id,
            $query,
            function ($raw, $typed = null) {
                return TypedHydrator::resolve(Refund::class, new TypedResult($raw, $typed, false), $this->context);
            }
        );
    }

    protected function listCancelsPage(array $query)
    {
        $bridge = $this->context->bridge();
        return ListDispatcher::listCancels(
            $bridge,
            $this->storeId,
            $this->id,
            $query,
            function ($raw, $typed = null) {
                return TypedHydrator::resolve(Cancel::class, new TypedResult($raw, $typed, false), $this->context);
            }
        );
    }
}
