<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

use DateInterval;
use DateTime;
use Exception;
use InvalidArgumentException;
use Money\Money;
use ReflectionProperty;
use UnivaPay\Models\BaseOnlineDataBrand;
use UnivaPay\Models\CancelCreateRequest;
use UnivaPay\Models\CancelUpdateRequest;
use UnivaPay\Models\ChargeCaptureRequest;
use UnivaPay\Models\ChargeCreateRequest;
use UnivaPay\Models\ChargeCreateRequestRedirect;
use UnivaPay\Models\ChargeUpdateRequest;
use UnivaPay\Models\GenericMetadata;
use UnivaPay\Models\InstallmentFixedCycles;
use UnivaPay\Models\RefundCreateRequest;
use UnivaPay\Models\RefundUpdateRequest;
use UnivaPay\Models\SubscriptionCreateRequest;
use UnivaPay\Models\SubscriptionInstallmentPlan;
use UnivaPay\Models\SubscriptionPatchPaymentRequest;
use UnivaPay\Models\SubscriptionPlanSettings;
use UnivaPay\Models\SubscriptionScheduleSettings;
use UnivaPay\Models\SubscriptionSimulationPlanSettings;
use UnivaPay\Models\SubscriptionSimulationRequest;
use UnivaPay\Models\SubscriptionUpdateRequest;
use UnivaPay\Models\SubscriptionUpdateScheduleSettings;
use UnivaPay\Models\TokenCreateCardData;
use UnivaPay\Models\TokenCreateCardDataCvvAuthorize;
use UnivaPay\Models\TokenCreateCardDataThreeDs;
use UnivaPay\Models\TokenCreateKonbiniData;
use UnivaPay\Models\TokenCreateOnlineData;
use UnivaPay\Models\TokenCreatePaidyData;
use UnivaPay\Models\TokenCreatePaidyDataShippingAddress;
use UnivaPay\Models\TokenCreatePhoneNumber;
use UnivaPay\Models\TokenCreateQrMerchantData;
use UnivaPay\Models\TokenCreateQrScanData;
use UnivaPay\Models\TransactionTokenCreateRequest;
use UnivaPay\Models\TransactionTokenCreateRequestMetadata;
use UnivaPay\Models\TransactionTokenUpdateRequest;
use UnivaPay\Models\TransactionTokenUpdateRequestData;
use Univapay\Compat\Enums\Field;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\Period;
use Univapay\Compat\Enums\Reason;
use Univapay\Compat\Enums\RefundReason;
use Univapay\Compat\Enums\SubscriptionStatus;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\PaymentMethod\ApplePayPayment;
use Univapay\Compat\Resources\PaymentMethod\CardPayment;
use Univapay\Compat\Resources\PaymentMethod\CardPaymentPatch;
use Univapay\Compat\Resources\PaymentMethod\ConvenienceStorePayment;
use Univapay\Compat\Resources\PaymentMethod\OnlinePayment;
use Univapay\Compat\Resources\PaymentMethod\PaidyPayment;
use Univapay\Compat\Resources\PaymentMethod\PaymentMethod;
use Univapay\Compat\Resources\PaymentMethod\PaymentMethodPatch;
use Univapay\Compat\Resources\PaymentMethod\QrMerchantPayment;
use Univapay\Compat\Resources\PaymentMethod\QrScanPayment;
use Univapay\Compat\Resources\PaymentThreeDS;
use Univapay\Compat\Resources\Redirect;
use Univapay\Compat\Resources\Subscription\InstallmentPlan;
use Univapay\Compat\Resources\Subscription\ScheduleSettings;
use Univapay\Compat\Resources\Subscription\SubscriptionPlan;
use Univapay\Compat\Utility\DateUtils;

/**
 * @internal
 *
 * Builds the new SDK's generated typed *request* models from ported old-SDK payment-method /
 * argument shapes. Every builder below is deliberately driven by the OLD class's own
 * `jsonSerialize()` output rather than reflection into its (mostly private, getter-less)
 * properties: the old payment-method/DTO classes were never meant to be introspected from outside,
 * only serialized, so `jsonSerialize()` -- the wire-truth surface this factory must preserve --
 * also doubles as this factory's only reliable read path onto them. This has the added benefit
 * that whatever old-SDK guard fires inside a `jsonSerialize()` (e.g. `ScheduleSettings`'s
 * future-`start_on` check) fires here too, for free, instead of needing to be re-implemented.
 *
 * 3DS passthrough: the generated `ChargeCreateRequestThreeDs` model cannot express
 * `PaymentThreeDS`'s wire shape (no MPI fields, rejects `IF_AVAILABLE`/`PROVIDED` modes,
 * force-emits `mode: normal`). Every place this factory handles a `PaymentThreeDS`, it NEVER
 * constructs a `ChargeCreateRequestThreeDs` -- it writes `$paymentThreeDS->jsonSerialize()`'s raw
 * array directly into the request's private `$threeDs` slot via `setPrivateProperty()` below
 * (bypassing the typed setter, which only accepts a `ChargeCreateRequestThreeDs` instance).
 * `addAdditionalProperty('three_ds', ...)` is NOT usable for this: `three_ds` is already a KNOWN
 * property name on `ChargeCreateRequest` and `SubscriptionCreateRequest` (mapped to the typed
 * setter), and `addAdditionalProperty()` throws `InvalidArgumentException` for any name that
 * conflicts with a model's own property list. Because
 * `ChargeCreateRequest`/`SubscriptionCreateRequest::jsonSerialize()` simply does
 * `$json['three_ds'] = $this->threeDs;` with no further type check, writing a plain array there
 * serializes exactly as if it were the object's own `jsonSerialize()` output -- which is exactly
 * what we want.
 *
 * Metadata: the generated `GenericMetadata`'s `additionalProperties` accept
 * `anyOf(string,float,bool,array[])`, but `TransactionTokenCreateRequestMetadata`'s only accept
 * `oneOf(string,bool,float)` -- scalars only, no arrays. Where the target model's
 * `additionalProperties` are scalar-only and the old metadata array is not all-scalar,
 * `applyMetadata()` falls back to the same private-property passthrough trick as `three_ds` on the
 * ROOT request object (`addAdditionalProperty('metadata', ...)` is unusable for the same
 * name-conflict reason -- `metadata` is a known property on every request model here); otherwise
 * it builds the typed metadata object directly (known dash-keys via their typed setters, anything
 * else via `addAdditionalProperty()`).
 */
final class RequestModelFactory
{
    /**
     * @param mixed $localCustomerId
     */
    public static function tokenCreate(
        PaymentMethod $paymentMethod,
        $localCustomerId = null
    ): TransactionTokenCreateRequest {
        if ($localCustomerId !== null) {
            // PERMANENT guard (see `Resources\Store::getCustomerId()`): `UnivapayClient::
            // createToken()` deliberately NEVER forwards `$localCustomerId` into this method (see
            // that method's class doc, "createToken(): the RECURRING + local-customer-id branch")
            // -- it only ever reaches `Store::getCustomerId()` for the `gopay-customer-id` metadata
            // injection, gated on `TokenType::RECURRING()`. This factory's OWN guard is coarser and
            // type-agnostic on purpose: if a caller ever invokes `tokenCreate()` directly with a
            // non-null `$localCustomerId` (bypassing the client's smarter RECURRING-only gate),
            // there is no safe silent behavior to fall back to -- silently dropping it would ship a
            // token quietly missing metadata the old SDK guaranteed for that exact call shape.
            // Fails loud instead.
            throw new UnivapayUnsupportedFeatureError(
                'RequestModelFactory::tokenCreate() does not accept a local customer id directly -- '
                . 'use UnivapayClient::createToken($payment, $localCustomerId) instead, which routes '
                . 'RECURRING-type tokens through Store::getCustomerId() for gopay-customer-id metadata'
            );
        }

        $serialized = $paymentMethod->jsonSerialize();
        $data = self::buildTokenCreateData($paymentMethod, isset($serialized['data']) ? $serialized['data'] : []);

        $type = isset($serialized['type']) ? $serialized['type'] : TokenType::ONE_TIME()->getValue();
        $request = new TransactionTokenCreateRequest($serialized['payment_type'], $type, $data);

        if (isset($serialized['email'])) {
            $request->setEmail($serialized['email']);
        }
        if (isset($serialized['usage_limit'])) {
            $request->setUsageLimit($serialized['usage_limit']);
        }
        if (isset($serialized['ip_address'])) {
            $request->setIpAddress($serialized['ip_address']);
        }
        self::applyMetadata(
            $request,
            'setMetadata',
            function (array $metadata) {
                return self::buildTokenCreateMetadata($metadata);
            },
            isset($serialized['metadata']) ? $serialized['metadata'] : null,
            false // TransactionTokenCreateRequestMetadata's additionalProperties are scalar-only.
        );

        return $request;
    }

    /**
     * @return TokenCreateCardData|TokenCreateKonbiniData|TokenCreateOnlineData|TokenCreateQrScanData
     *         |TokenCreateQrMerchantData|TokenCreatePaidyData
     */
    private static function buildTokenCreateData(PaymentMethod $paymentMethod, array $data)
    {
        if ($paymentMethod instanceof CardPayment) {
            return self::buildCardData($data);
        }
        if ($paymentMethod instanceof ConvenienceStorePayment) {
            return self::buildKonbiniData($data);
        }
        if ($paymentMethod instanceof OnlinePayment) {
            return self::buildOnlineData($data);
        }
        if ($paymentMethod instanceof QrScanPayment) {
            return self::buildQrScanData($data);
        }
        if ($paymentMethod instanceof QrMerchantPayment) {
            return self::buildQrMerchantData($data);
        }
        if ($paymentMethod instanceof PaidyPayment) {
            return self::buildPaidyData($data);
        }
        if ($paymentMethod instanceof ApplePayPayment) {
            // Final unsupported list (plan "Scope correction"), not spec backlog -- no TODO.
            throw new UnivapayUnsupportedFeatureError('ApplePayPayment token creation');
        }
        throw new UnivapayUnsupportedFeatureError(get_class($paymentMethod) . ' token creation');
    }

    private static function buildCardData(array $data): TokenCreateCardData
    {
        $cardData = new TokenCreateCardData(
            (string) $data['card_number'],
            // Allowed delta (plan): old passes exp_month/exp_year through untouched (may be int
            // or string); the new SDK's constructor requires string.
            (string) $data['exp_month'],
            (string) $data['exp_year']
        );
        if (isset($data['cardholder'])) {
            $cardData->setCardholder($data['cardholder']);
        }
        if (isset($data['cvv'])) {
            $cardData->setCvv((string) $data['cvv']);
        }
        foreach (['line1', 'line2', 'state', 'city', 'country', 'zip'] as $field) {
            if (isset($data[$field])) {
                $setter = 'set' . ucfirst($field);
                $cardData->$setter($data[$field]);
            }
        }
        if (isset($data['phone_number'])) {
            $cardData->setPhoneNumber(self::buildTokenCreatePhoneNumber($data['phone_number']));
        }
        if (isset($data['cvv_authorize'])) {
            $cvvAuthorize = new TokenCreateCardDataCvvAuthorize();
            $cvvAuthorize->setEnabled((bool) $data['cvv_authorize']['enabled']);
            if (isset($data['cvv_authorize']['currency'])) {
                // moneyphp's `Currency::jsonSerialize()` is the source here (old `CvvAuthorize::
                // jsonSerialize()` calls it directly) -- cast defensively rather than assume its
                // exact return shape, matching this repo's existing moneyphp-defensive-casting
                // convention (see `Support\MoneyHelper`'s class doc).
                $cvvAuthorize->setCurrency((string) $data['cvv_authorize']['currency']);
            }
            $cardData->setCvvAuthorize($cvvAuthorize);
        }
        if (isset($data['three_ds'])) {
            // Unlike charge-level `PaymentThreeDS`, the token-level `TokenThreeDS` has no MPI
            // fields and its wire shape ({enabled, redirect_endpoint}) is a 1:1 match for the
            // generated `TokenCreateCardDataThreeDs` -- no passthrough needed here.
            $threeDs = new TokenCreateCardDataThreeDs();
            $threeDs->setEnabled((bool) $data['three_ds']['enabled']);
            if (isset($data['three_ds']['redirect_endpoint'])) {
                $threeDs->setRedirectEndpoint($data['three_ds']['redirect_endpoint']);
            }
            $cardData->setThreeDs($threeDs);
        }
        return $cardData;
    }

    private static function buildKonbiniData(array $data): TokenCreateKonbiniData
    {
        $konbiniData = new TokenCreateKonbiniData(
            (string) $data['customer_name'],
            (string) $data['convenience_store'],
            self::buildTokenCreatePhoneNumber($data['phone_number'])
        );
        if (isset($data['expiration_period'])) {
            $konbiniData->setExpirationPeriod($data['expiration_period']);
        }
        return $konbiniData;
    }

    private static function buildOnlineData(array $data): TokenCreateOnlineData
    {
        if (!isset($data['call_method'])) {
            // Old `OnlinePayment` allows a null call method (its constructor's `$callMethod`
            // param defaults to null); `TokenCreateOnlineData`'s constructor requires one. There
            // is no old-SDK default to fall back to without silently changing behavior.
            throw new UnivapayUnsupportedFeatureError(
                'OnlinePayment without an explicit call method (TokenCreateOnlineData requires one)'
            );
        }

        // Case-fold discovery: unlike every other payment method ported in this class,
        // `OnlinePayment::jsonSerialize()` (and `QrMerchantPayment`/`QrScanPayment`) serializes
        // its enums via `->getName()`, not `->getValue()` -- verbatim
        // upstream behavior, not a porting slip (see `OnlinePayment`'s class doc). Old
        // `TypedEnum::getName()` returns the ENUM CASE'S METHOD NAME EXACTLY AS CALLED
        // (`debug_backtrace()`-derived, uppercase, e.g. `WE_CHAT_ONLINE`), while `getValue()`
        // lowercases it (or uses an explicit override, e.g. `OnlineBrand::TOUCH_N_GO()`'s value
        // is `tng`, not `touch_n_go`). The generated `BaseOnlineDataBrand`/`BaseOnlineDataCallMethod`/
        // `BaseOnlineDataOsType` enums only know the LOWERCASE wire values. For the brands/call
        // methods/os types the generated enums actually cover, `strtolower(getName())` always
        // equals `getValue()` (none of `ALIPAY_ONLINE`, `ALIPAY_PLUS_ONLINE`, `PAY_PAY_ONLINE`,
        // `WE_CHAT_ONLINE`, `D_BARAI_ONLINE`, or any `CallMethod`/`OsType` case use an
        // overridden value), so case-folding here is safe and correct, not a guess.
        $brand = strtolower($data['brand']);
        $callMethod = strtolower($data['call_method']);

        try {
            BaseOnlineDataBrand::checkValue($brand);
        } catch (Exception $e) {
            // Coverage gap, not a porting bug: the generated `BaseOnlineDataBrand` enum currently
            // covers 5 of the ~24 old `OnlineBrand` cases (alipay_online, alipay_plus_online,
            // pay_pay_online, we_chat_online, d_barai_online) -- everything else (alipay_china,
            // boost, dana, gcash, kakaopay, touch_n_go, ...) would otherwise 500 at serialize
            // time inside the generated model's own `jsonSerialize()` with a bare `Exception`.
            // Spec-backlog coverage gap, not a porting bug.
            throw new UnivapayUnsupportedFeatureError("OnlinePayment brand '{$data['brand']}'");
        }
        $onlineData = new TokenCreateOnlineData($brand, $callMethod);
        if (isset($data['os_type'])) {
            $onlineData->setOsType(strtolower($data['os_type']));
        }
        if (isset($data['user_identifier'])) {
            $onlineData->setUserIdentifier($data['user_identifier']);
        }
        return $onlineData;
    }

    private static function buildTokenCreatePhoneNumber(array $phoneNumber): TokenCreatePhoneNumber
    {
        return new TokenCreatePhoneNumber(
            (string) $phoneNumber['country_code'],
            (string) $phoneNumber['local_number']
        );
    }

    /**
     * QR CPM/scan. `QrScanPayment::jsonSerialize()`'s `data` is `{scanned_qr}` only -- a 1:1 match
     * for the generated `TokenCreateQrScanData`, no passthrough needed.
     */
    private static function buildQrScanData(array $data): TokenCreateQrScanData
    {
        return new TokenCreateQrScanData((string) $data['scanned_qr']);
    }

    /**
     * QR MPM/merchant. `QrMerchantPayment::jsonSerialize()` serializes its
     * `QrBrandMerchant` enum via `->getName()` (old-SDK-verbatim, see `buildOnlineData()`'s class
     * doc for the same case-folding discovery on `OnlinePayment`) -- i.e. `$data['brand']` here is
     * the enum's UPPERCASE method name (e.g. `PAY_PAY_MERCHANT`), not its lowercase `getValue()`.
     * Unlike `BaseOnlineDataBrand`, the generated `TokenCreateQrMerchantData::setBrand()` has NO
     * `checkValue()`/enum validation at all (plain string, validated server-side against an
     * "open value set" per its own doc) -- so, matching `buildOnlineData()`'s established
     * case-folding convention, this simply lowercases rather than throwing on an unknown brand.
     * Coverage gap (genuine, not a porting mistake): `QrBrandMerchant`'s two explicitly-overridden
     * cases (`TOUCH_N_GO()` -> wire `tng`, `PUBLICBANK()` -> wire `pbengagemy`) do NOT satisfy
     * `strtolower(getName()) === getValue()` the way every other case does -- lowercasing the NAME
     * sends `touch_n_go`/`publicbank` on the wire instead of the enum's own declared `tng`/
     * `pbengagemy` values. This reproduces `QrMerchantPayment::jsonSerialize()`'s own pre-existing
     * `->getName()` choice exactly (not something introduced by this factory); flagged in this
     * task's final report as a spec-backlog wire-parity gap for those two brands specifically.
     */
    private static function buildQrMerchantData(array $data): TokenCreateQrMerchantData
    {
        return new TokenCreateQrMerchantData(strtolower((string) $data['brand']));
    }

    /**
     * Paidy. `PaidyPayment::jsonSerialize()`'s `data` is `PaidyData::jsonSerialize()`
     * -- `{paidy_token, shipping_address: {line1,line2,state,city,country,zip}, phone_number}`.
     * Mapping notes:
     * - `shipping_address.country` has NO counterpart on the generated
     *   `TokenCreatePaidyDataShippingAddress` (JP-only feature, per spec authoring appendix) --
     *   dropped, not passed through (no `three_ds`-style name conflict here; it is simply absent
     *   from the generated model's `$propertyNames`, so silently omitting it is the correct
     *   behavior, not a gap).
     * - `phone_number` on the old wire is EITHER a plain string (untyped `PaidyData::$phoneNumber`,
     *   see that class's own class doc) OR the nested-object
     *   shape old `PhoneNumber::jsonSerialize()` produces (`{country_code, local_number}` --
     *   reached whenever `PaidyPayment`'s own constructor guard validated a real `PhoneNumber`
     *   instance, which it requires to be JP, `PhoneNumber::JP`). The generated
     *   `TokenCreatePaidyData::setPhoneNumber()` only accepts a plain STRING ("e.g. '08012341234'",
     *   no country code component at all) -- so the nested-object shape is collapsed to its
     *   `local_number` alone here (the only lossy step in this mapping, and lossless in practice:
     *   the guard already rejected any non-JP country code before this factory ever sees the
     *   value, and JP numbers on this wire are written without a leading country code).
     */
    private static function buildPaidyData(array $data): TokenCreatePaidyData
    {
        $shippingAddress = self::buildTokenCreatePaidyShippingAddress($data['shipping_address']);
        $paidyData = new TokenCreatePaidyData((string) $data['paidy_token'], $shippingAddress);
        if (isset($data['phone_number'])) {
            $phoneNumber = $data['phone_number'];
            $paidyData->setPhoneNumber(
                is_array($phoneNumber) ? (string) $phoneNumber['local_number'] : (string) $phoneNumber
            );
        }
        return $paidyData;
    }

    private static function buildTokenCreatePaidyShippingAddress(
        array $address
    ): TokenCreatePaidyDataShippingAddress {
        // $address['zip'] is guaranteed non-null here: PaidyPayment's own constructor guard
        // (Field::ZIP()/Reason::REQUIRED_VALUE()) already rejected a null zip before this factory
        // is ever reached -- see that class's constructor.
        $shipping = new TokenCreatePaidyDataShippingAddress((string) $address['zip']);
        foreach (['line1', 'line2', 'city', 'state'] as $field) {
            if (isset($address[$field])) {
                $setter = 'set' . ucfirst($field);
                $shipping->$setter($address[$field]);
            }
        }
        return $shipping;
    }

    /**
     * @param mixed $tokenId
     */
    public static function chargeCreate(
        $tokenId,
        Money $money,
        $capture = null,
        ?DateTime $captureAt = null,
        ?array $metadata = null,
        $onlyDirectCurrency = null,
        ?Redirect $redirect = null,
        ?PaymentThreeDS $threeDS = null
    ): ChargeCreateRequest {
        $request = new ChargeCreateRequest(
            (string) $tokenId,
            MoneyHelper::amount($money),
            MoneyHelper::currency($money)
        );

        // Forced-default suppression: `ChargeCreateRequest::$capture` defaults to `true` and is
        // always `isset()` unless explicitly nulled. Old `createCharge()`/`TransactionToken::
        // createCharge()` only ever emit a `capture` key when the caller's `$capture` arg is
        // non-null (`isset($capture) ? ['capture' => $capture] : []`); passing null through here
        // makes `isset($this->capture)` false again on the generated side too, so the key is
        // omitted exactly like the old wire.
        $request->setCapture($capture);

        if ($captureAt !== null) {
            // DateTimeHelper mutation warning (plan): `ChargeCreateRequest::jsonSerialize()`
            // calls `DateTimeHelper::toRfc3339DateTime($this->captureAt)`, which mutates its
            // argument's timezone to UTC IN PLACE (`DateHelper::toRfc3339DateTime` calls
            // `$date->setTimeZone(...)` on a mutable `DateTime`, verified against
            // vendor/apimatic/core/src/Utils/DateHelper.php:336). Clone before handing it to the
            // generated setter so the CALLER's own `$captureAt` object is never silently rewritten
            // out from under them. Allowed delta (plan): old sent the caller's own UTC offset
            // (`$captureAt->format(DateTime::ATOM)`); the new wire is always UTC-normalized.
            $request->setCaptureAt(clone $captureAt);
        }

        self::applyMetadata(
            $request,
            'setMetadata',
            function (array $metadata) {
                return self::buildGenericMetadata($metadata);
            },
            $metadata
        );

        if ($onlyDirectCurrency !== null) {
            // `only_direct_currency` has NO field on the generated `ChargeCreateRequest` at all
            // (only on response-side `Charge`/`Subscription` models) -- genuine spec gap, not a
            // three_ds-style name conflict, so the public `addAdditionalProperty()` API works
            // here without needing the private-property bypass.
            $request->addAdditionalProperty('only_direct_currency', $onlyDirectCurrency);
        }

        if ($redirect !== null) {
            // Unlike `PaymentThreeDS`, old `Redirect::jsonSerialize()` only ever emits `endpoint`
            // (redirectId is response-only and was never sent on create) -- a 1:1 match for the
            // generated `ChargeCreateRequestRedirect`, which only HAS an `endpoint` field. No
            // passthrough needed.
            $serializedRedirect = $redirect->jsonSerialize();
            $typedRedirect = new ChargeCreateRequestRedirect();
            if (isset($serializedRedirect['endpoint'])) {
                $typedRedirect->setEndpoint($serializedRedirect['endpoint']);
            }
            $request->setRedirect($typedRedirect);
        }

        if ($threeDS !== null) {
            // 3DS passthrough -- see class doc. Never a typed `ChargeCreateRequestThreeDs`.
            self::setPrivateProperty($request, 'threeDs', $threeDS->jsonSerialize());
        }

        return $request;
    }

    public static function refundCreate(
        Money $money,
        ?RefundReason $reason = null,
        $message = null,
        ?array $metadata = null
    ): RefundCreateRequest {
        if ($reason !== null && RefundReason::CHARGEBACK() === $reason) {
            // Ported verbatim from old `Resources\Charge::createRefund()`: CHARGEBACK is a
            // read-only, server-assigned reason (old `RefundReason` doc: "Read only reason") --
            // the generated `RefundReasonRequest` enum doesn't even declare it, so letting it
            // through would fail downstream with a confusing generic Exception instead of this
            // clear, old-SDK-identical error.
            throw new UnivapayValidationError(Field::REASON(), Reason::INVALID_PERMISSIONS());
        }

        $request = new RefundCreateRequest(MoneyHelper::amount($money), MoneyHelper::currency($money));
        if ($reason !== null) {
            $request->setReason($reason->getValue());
        }
        if ($message !== null) {
            $request->setMessage($message);
        }
        self::applyMetadata(
            $request,
            'setMetadata',
            function (array $metadata) {
                return self::buildGenericMetadata($metadata);
            },
            $metadata
        );

        return $request;
    }

    /**
     * Old `Charge::capture(?Money $money = null)` sends NO body at all when $money is null (the
     * server captures the outstanding authorized amount and the old SDK returns bare `true`).
     * `Charge::capture()` special-cases null BEFORE ever calling this factory, so this method only
     * ever receives a non-null `Money`.
     */
    public static function chargeCapture(Money $money): ChargeCaptureRequest
    {
        // ChargeCaptureRequest's fields are both optional (no constructor -- set via
        // setAmount()/setCurrency(), verified against sdk/php/src/Models/ChargeCaptureRequest.php).
        // `Charge::capture()` already special-cases the null-$money case before ever reaching this
        // method (never sends a body at all then), so this method itself is only ever called with
        // a non-null $money -- both setters are always called.
        $request = new ChargeCaptureRequest();
        $request->setAmount(MoneyHelper::amount($money));
        $request->setCurrency(MoneyHelper::currency($money));
        return $request;
    }

    /**
     * Builds the request `Resources\Charge::patch(array $metadata)` hands to `update()` ->
     * `updateCall()`. `ChargeUpdateRequest` only HAS a `metadata` field -- old `Charge::patch()`
     * only ever calls `update(['metadata' => $metadata])`, so `metadata` is the only key this
     * needs to special-case; anything else lands via `addAdditionalProperty()` (no name conflict,
     * since `metadata` is excluded from the passthrough loop below), matching this class's
     * established genuine-spec-gap convention rather than silently dropping it.
     */
    public static function chargeUpdate(array $updates): ChargeUpdateRequest
    {
        $request = new ChargeUpdateRequest();
        foreach ($updates as $key => $value) {
            if ($key !== 'metadata') {
                $request->addAdditionalProperty((string) $key, $value);
            }
        }
        self::applyMetadata(
            $request,
            'setMetadata',
            function (array $metadata) {
                return self::buildGenericMetadata($metadata);
            },
            isset($updates['metadata']) ? $updates['metadata'] : null
        );
        return $request;
    }

    /**
     * `Resources\Charge::cancel(?array $metadata)` builds this to POST
     * `CancelsApi::createCancel()` -- `CancelCreateRequest` only has a `metadata` field, matching
     * old `Charge::cancel()`'s `stripNulls(['metadata' => $metadata])` payload exactly.
     */
    public static function cancelCreate(?array $metadata = null): CancelCreateRequest
    {
        $request = new CancelCreateRequest();
        self::applyMetadata(
            $request,
            'setMetadata',
            function (array $metadata) {
                return self::buildGenericMetadata($metadata);
            },
            $metadata
        );
        return $request;
    }

    /**
     * Backs `Resources\Cancel`'s `Resource::updateCall()` -- old `Cancel` never had a
     * dedicated `patch()` of its own (only the generic inherited `Resource::update($updates)`),
     * and `CancelUpdateRequest` only has a `metadata` field, same shape as `chargeUpdate()` above.
     */
    public static function cancelUpdate(array $updates): CancelUpdateRequest
    {
        $request = new CancelUpdateRequest();
        foreach ($updates as $key => $value) {
            if ($key !== 'metadata') {
                $request->addAdditionalProperty((string) $key, $value);
            }
        }
        self::applyMetadata(
            $request,
            'setMetadata',
            function (array $metadata) {
                return self::buildGenericMetadata($metadata);
            },
            isset($updates['metadata']) ? $updates['metadata'] : null
        );
        return $request;
    }

    /**
     * Backs `Resources\Refund`'s `Resource::updateCall()` -- old `Refund` never had a
     * dedicated `patch()` either, only the generic inherited `Resource::update($updates)`.
     * `RefundUpdateRequest` additionally supports `message`/`reason` (unlike Charge/Cancel's
     * metadata-only update requests), both mapped via their typed setters when present; `reason`
     * accepts either a raw string or a `Compat\Enums\RefundReason` instance since old-SDK callers
     * could plausibly hand either through the generic array-based `update()` entry point.
     */
    public static function refundUpdate(array $updates): RefundUpdateRequest
    {
        $request = new RefundUpdateRequest();
        foreach ($updates as $key => $value) {
            if ($key !== 'metadata' && $key !== 'message' && $key !== 'reason') {
                $request->addAdditionalProperty((string) $key, $value);
            }
        }
        if (isset($updates['message'])) {
            $request->setMessage($updates['message']);
        }
        if (isset($updates['reason'])) {
            $reason = $updates['reason'];
            $request->setReason($reason instanceof RefundReason ? $reason->getValue() : $reason);
        }
        self::applyMetadata(
            $request,
            'setMetadata',
            function (array $metadata) {
                return self::buildGenericMetadata($metadata);
            },
            isset($updates['metadata']) ? $updates['metadata'] : null
        );
        return $request;
    }

    /**
     * Superset of both old call sites this factory serves: `UnivapayClient::createSubscription()`
     * (the "old 9-arg" signature: $tokenId, $money, $period, $initialAmount, $scheduleSettings,
     * $subscriptionPlan, $installmentPlan, $metadata, $threeDS -- called with the token-level-only
     * params below always null) and `TransactionToken::createSubscription()` (the richer
     * 12-argument token-level variant, which additionally supports $onlyDirectCurrency,
     * $firstChargeAuthorizationOnly, $firstChargeCaptureAfter, $cyclicalPeriod).
     *
     * @param mixed $tokenId
     * @param mixed $onlyDirectCurrency
     * @param mixed $firstChargeAuthorizationOnly
     */
    public static function subscriptionCreate(
        $tokenId,
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
    ): SubscriptionCreateRequest {
        $request = new SubscriptionCreateRequest(
            (string) $tokenId,
            MoneyHelper::amount($money),
            MoneyHelper::currency($money)
        );

        if ($period !== null) {
            $request->setPeriod($period->getValue());
        }
        if ($cyclicalPeriod !== null) {
            $request->setCyclicalPeriod(DateUtils::asPeriodString($cyclicalPeriod));
        }
        if ($initialAmount !== null) {
            // `SubscriptionCreateRequest` has a single top-level `currency` (matching $money's,
            // per old `validateCreateSubscription`'s same-currency guard, a preflight concern) --
            // no separate initial-amount currency field to lose here.
            $request->setInitialAmount(MoneyHelper::amount($initialAmount));
        }
        if ($scheduleSettings !== null) {
            $request->setScheduleSettings(self::buildSubscriptionScheduleSettings($scheduleSettings));
        }
        if ($subscriptionPlan !== null) {
            $request->setSubscriptionPlan(self::buildSubscriptionPlanSettings($subscriptionPlan));
        }
        if ($installmentPlan !== null) {
            $request->setInstallmentPlan(self::buildSubscriptionInstallmentPlan($installmentPlan));
        }
        self::applyMetadata(
            $request,
            'setMetadata',
            function (array $metadata) {
                return self::buildGenericMetadata($metadata);
            },
            $metadata
        );

        if ($onlyDirectCurrency !== null) {
            // Same gap as chargeCreate(): no field on the generated request at all.
            $request->addAdditionalProperty('only_direct_currency', $onlyDirectCurrency);
        }

        if ($firstChargeAuthorizationOnly !== null) {
            $request->setFirstChargeAuthorizationOnly($firstChargeAuthorizationOnly);
        } else {
            // Forced-default suppression: `SubscriptionCreateRequest::$firstChargeAuthorizationOnly`
            // defaults to `false` and is always `isset()`. Old `TransactionToken::
            // createSubscription()` only emits this key when the caller's arg is non-null
            // (`isset($firstChargeAuthorizationOnly) ? [...] : []`) -- explicitly null it back out
            // so a plain client-level 9-arg call (which never sets this at all) doesn't gain a
            // wire key the old SDK never had.
            self::setPrivateProperty($request, 'firstChargeAuthorizationOnly', null);
        }
        if ($firstChargeCaptureAfter !== null) {
            $request->setFirstChargeCaptureAfter(DateUtils::asPeriodString($firstChargeCaptureAfter));
        }

        if ($threeDS !== null) {
            // 3DS passthrough -- see class doc; same passthrough as chargeCreate().
            self::setPrivateProperty($request, 'threeDs', $threeDS->jsonSerialize());
        }

        return $request;
    }

    /**
     * Superset backing `Resources\Subscription::patch()`'s old 9-arg signature. All
     * preflight guards (CANCELED, isTokenPatchable, isEditable variants, the status-transition
     * switch, PLAN_ALREADY_SET) live on `Subscription::patch()` itself, verbatim-ported from the
     * old SDK -- this factory only builds the outbound request once the guards have already
     * passed.
     *
     * The generated `SubscriptionUpdateRequest` declares TYPED
     * `period`/`cyclical_period`/`initial_amount`/`subscription_plan`/`installment_plan` setters
     * (verified against `sdk/php/src/Models/SubscriptionUpdateRequest.php`), alongside
     * `transaction_token_id`, `metadata`, `status`, `schedule_settings`, and `next_payment`. All
     * five route through their own typed setters below, reusing the same
     * `buildSubscriptionPlanSettings()`/`buildSubscriptionInstallmentPlan()` builders `subscriptionCreate()`
     * already uses (identical target model shapes on both the create- and patch-side requests).
     * Old `patch()`'s own dead-code `if (isset($money)) { $payload += $money->jsonSerialize(); }`
     * branch (its `$money` local is never assigned anywhere in the old method -- verified against
     * scratchpad/univapay-php-sdk/src/Univapay/Resources/Subscription.php's `patch()`) is NOT
     * reproduced here: since it can never execute in the original either, omitting it changes
     * nothing observable.
     *
     * @param mixed $transactionTokenId
     */
    public static function subscriptionPatch(
        $transactionTokenId,
        ?Money $initialAmount,
        ?Period $period,
        ?ScheduleSettings $scheduleSettings,
        ?SubscriptionStatus $status,
        ?array $metadata,
        ?SubscriptionPlan $subscriptionPlan,
        ?InstallmentPlan $installmentPlan,
        ?DateInterval $cyclicalPeriod
    ): SubscriptionUpdateRequest {
        $request = new SubscriptionUpdateRequest();

        if ($transactionTokenId !== null) {
            $request->setTransactionTokenId((string) $transactionTokenId);
        }
        if ($status !== null) {
            $request->setStatus($status->getValue());
        }
        if ($scheduleSettings !== null) {
            $request->setScheduleSettings(self::buildSubscriptionUpdateScheduleSettings($scheduleSettings));
        }
        self::applyMetadata(
            $request,
            'setMetadata',
            function (array $metadata) {
                return self::buildGenericMetadata($metadata);
            },
            $metadata
        );

        // Typed setters exist for all five below; reuses the same builders subscriptionCreate()
        // already has (identical target model shapes).
        if ($initialAmount !== null) {
            $request->setInitialAmount(MoneyHelper::amount($initialAmount));
        }
        if ($period !== null) {
            $request->setPeriod($period->getValue());
        }
        if ($cyclicalPeriod !== null) {
            $request->setCyclicalPeriod(DateUtils::asPeriodString($cyclicalPeriod));
        }
        if ($subscriptionPlan !== null) {
            $request->setSubscriptionPlan(self::buildSubscriptionPlanSettings($subscriptionPlan));
        }
        if ($installmentPlan !== null) {
            $request->setInstallmentPlan(self::buildSubscriptionInstallmentPlan($installmentPlan));
        }

        return $request;
    }

    /**
     * `SubscriptionUpdateScheduleSettings` (PATCH-side) is a narrower model than
     * `SubscriptionScheduleSettings` (CREATE-side, see `buildSubscriptionScheduleSettings()`
     * below): no `zone_id` field, though it does have a typed `preserve_end_of_month` setter
     * (verified against `sdk/php/src/Models/SubscriptionUpdateScheduleSettings.php`) -- only
     * `zone_id` remains a genuine passthrough gap.
     */
    private static function buildSubscriptionUpdateScheduleSettings(
        ScheduleSettings $old
    ): SubscriptionUpdateScheduleSettings {
        $serialized = $old->jsonSerialize();
        $new = new SubscriptionUpdateScheduleSettings();
        if (isset($serialized['start_on'])) {
            $new->setStartOn(new DateTime($serialized['start_on']));
        }
        if (isset($serialized['retry_interval'])) {
            $new->setRetryInterval($serialized['retry_interval']);
        }
        // Forced-default suppression (same pattern as buildSubscriptionScheduleSettings() below):
        // no old-SDK termination_mode equivalent on PATCH either; the generated model
        // force-defaults it to 'immediate' and is always isset() unless explicitly nulled.
        $new->setTerminationMode(null);
        if (isset($serialized['zone_id'])) {
            $new->addAdditionalProperty('zone_id', $serialized['zone_id']);
        }
        if (isset($serialized['preserve_end_of_month'])) {
            $new->setPreserveEndOfMonth($serialized['preserve_end_of_month']);
        }
        return $new;
    }

    private static function buildSubscriptionScheduleSettings(ScheduleSettings $old): SubscriptionScheduleSettings
    {
        // Calling jsonSerialize() (rather than reading the public props directly) preserves the
        // old class's own future-`start_on` guard for free -- see class doc.
        $serialized = $old->jsonSerialize();
        $new = new SubscriptionScheduleSettings();
        if (isset($serialized['start_on'])) {
            $new->setStartOn(new DateTime($serialized['start_on']));
        }
        if (isset($serialized['zone_id'])) {
            $new->setZoneId($serialized['zone_id']);
        }
        if (isset($serialized['preserve_end_of_month'])) {
            $new->setPreserveEndOfMonth($serialized['preserve_end_of_month']);
        }
        if (isset($serialized['retry_interval'])) {
            $new->setRetryInterval($serialized['retry_interval']);
        }
        // Forced-default suppression: no old-SDK equivalent for `termination_mode` at all; the
        // generated model force-defaults it to 'immediate' (always isset()). Explicitly null it
        // out rather than emit a wire key the old SDK never had.
        $new->setTerminationMode(null);
        return $new;
    }

    private static function buildSubscriptionPlanSettings(SubscriptionPlan $old): SubscriptionPlanSettings
    {
        $serialized = $old->jsonSerialize();
        if ($serialized['plan_type'] === 'null') {
            // Old `SubscriptionPlanType::NONE()` (wire value 'null') exists only for PATCH-based
            // plan removal; the generated `PlanSettingsType` enum for CREATE only has
            // `fixed_cycles`/`fixed_cycle_amount`, no "none" sentinel. Fail loud rather than send
            // a `plan_type` value the generated model's own checkValue() would reject anyway.
            throw new UnivapayUnsupportedFeatureError(
                "SubscriptionPlan of type NONE on subscription create (PlanSettingsType has no 'none' value)"
            );
        }
        $new = new SubscriptionPlanSettings();
        $new->setPlanType($serialized['plan_type']);
        if (isset($serialized['fixed_cycles'])) {
            $new->setFixedCycles($serialized['fixed_cycles']);
        }
        if (isset($serialized['fixed_cycle_amount'])) {
            $new->setFixedCycleAmount($serialized['fixed_cycle_amount']);
        }
        return $new;
    }

    private static function buildSubscriptionInstallmentPlan(InstallmentPlan $old): SubscriptionInstallmentPlan
    {
        $serialized = $old->jsonSerialize();
        if ($serialized['plan_type'] === 'null') {
            // Same NONE-sentinel gap as SubscriptionPlanSettings above.
            throw new UnivapayUnsupportedFeatureError(
                "InstallmentPlan of type NONE on subscription create (InstallmentPlanType has no 'none' value)"
            );
        }
        $new = new SubscriptionInstallmentPlan();
        $new->setPlanType($serialized['plan_type']);
        if (isset($serialized['fixed_cycles'])) {
            try {
                InstallmentFixedCycles::checkValue($serialized['fixed_cycles']);
            } catch (Exception $e) {
                // Coverage gap: old `InstallmentPlan` accepts any $fixedCycles >= 2; the generated
                // `InstallmentFixedCycles` enum only accepts a fixed set of card-network cycle
                // counts (3, 5, 6, 10, 12, 15, 18, 20, 24). Fail loud with a clear message instead
                // of letting the generated model's own bare Exception surface downstream.
                throw new UnivapayUnsupportedFeatureError(
                    "InstallmentPlan fixed_cycles value '{$serialized['fixed_cycles']}' "
                    . '(generated InstallmentFixedCycles only accepts 3, 5, 6, 10, 12, 15, 18, 20, or 24)'
                );
            }
            $new->setFixedCycles($serialized['fixed_cycles']);
        }
        return $new;
    }

    /**
     * Backs `UnivapayClient::createSubscriptionSimulation()`. Old client-level
     * signature always passes `$scheduleSettings` as OPTIONAL (`= null`), but the generated
     * `SubscriptionSimulationRequest(int $amount, string $currency, string $paymentType,
     * SubscriptionScheduleSettings $scheduleSettings)` constructor requires a
     * `SubscriptionScheduleSettings` OBJECT (non-null) as its 4th positional argument -- there is
     * no PHP-level way to express "omit this argument" for a required constructor parameter.
     * Resolution: build an all-null-fields `ScheduleSettings` when the caller passed none at all
     * -- `SubscriptionScheduleSettings` has no REQUIRED properties of its own (every setter is
     * optional; `buildSubscriptionScheduleSettings()` only calls setters for keys present in the
     * old object's own `stripNulls()`-filtered `jsonSerialize()`), so an empty instance is a valid,
     * accepted request value, not a fabricated one -- this changes nothing observable if the
     * caller genuinely supplied no schedule settings.
     */
    public static function subscriptionSimulationCreate(
        PaymentType $paymentType,
        Money $money,
        ?Period $period,
        ?Money $initialAmount,
        ?ScheduleSettings $scheduleSettings,
        ?SubscriptionPlan $subscriptionPlan,
        ?InstallmentPlan $installmentPlan
    ): SubscriptionSimulationRequest {
        $request = new SubscriptionSimulationRequest(
            MoneyHelper::amount($money),
            MoneyHelper::currency($money),
            $paymentType->getValue(),
            self::buildSubscriptionScheduleSettings($scheduleSettings ?? new ScheduleSettings())
        );

        if ($period !== null) {
            $request->setPeriod($period->getValue());
        }
        if ($initialAmount !== null) {
            $request->setInitialAmount(MoneyHelper::amount($initialAmount));
        }
        if ($subscriptionPlan !== null) {
            $request->setSubscriptionPlan(self::buildSubscriptionSimulationPlanSettings($subscriptionPlan));
        }
        if ($installmentPlan !== null) {
            $request->setInstallmentPlan(self::buildSubscriptionSimulationInstallmentPlan($installmentPlan));
        }

        return $request;
    }

    private static function buildSubscriptionSimulationPlanSettings(
        SubscriptionPlan $old
    ): SubscriptionSimulationPlanSettings {
        $serialized = $old->jsonSerialize();
        if ($serialized['plan_type'] === 'null') {
            // Same NONE-sentinel gap as buildSubscriptionPlanSettings()/
            // buildSubscriptionInstallmentPlan() above -- SimulationPlanSettingsType has no "none"
            // value either.
            throw new UnivapayUnsupportedFeatureError(
                "SubscriptionPlan of type NONE on subscription simulation (SimulationPlanSettingsType "
                . "has no 'none' value)"
            );
        }
        $new = new SubscriptionSimulationPlanSettings();
        $new->setPlanType($serialized['plan_type']);
        if (isset($serialized['fixed_cycles'])) {
            $new->setFixedCycles($serialized['fixed_cycles']);
        }
        if (isset($serialized['fixed_cycle_amount'])) {
            $new->setFixedCycleAmount($serialized['fixed_cycle_amount']);
        }
        return $new;
    }

    private static function buildSubscriptionSimulationInstallmentPlan(
        InstallmentPlan $old
    ): SubscriptionSimulationPlanSettings {
        $serialized = $old->jsonSerialize();
        if ($serialized['plan_type'] === 'null') {
            throw new UnivapayUnsupportedFeatureError(
                "InstallmentPlan of type NONE on subscription simulation (SimulationPlanSettingsType "
                . "has no 'none' value)"
            );
        }
        // Unlike buildSubscriptionInstallmentPlan() (create-side, InstallmentFixedCycles-checked),
        // the simulation-side SubscriptionSimulationPlanSettings::setFixedCycles() has no
        // enum/checkValue restriction on the card-network cycle count at all -- a plain int, no
        // coverage-gap guard needed here.
        $new = new SubscriptionSimulationPlanSettings();
        $new->setPlanType($serialized['plan_type']);
        if (isset($serialized['fixed_cycles'])) {
            $new->setFixedCycles($serialized['fixed_cycles']);
        }
        return $new;
    }

    /**
     * @param PaymentMethodPatch|CardPaymentPatch $patch
     */
    public static function tokenPatch($patch): TransactionTokenUpdateRequest
    {
        if (!($patch instanceof PaymentMethodPatch)) {
            throw new InvalidArgumentException(
                'RequestModelFactory::tokenPatch() expects a PaymentMethodPatch or CardPaymentPatch'
            );
        }

        $serialized = $patch->jsonSerialize();
        $request = new TransactionTokenUpdateRequest();

        if (isset($serialized['email'])) {
            $request->setEmail($serialized['email']);
        }
        self::applyMetadata(
            $request,
            'setMetadata',
            function (array $metadata) {
                return self::buildGenericMetadata($metadata);
            },
            isset($serialized['metadata']) ? $serialized['metadata'] : null
        );

        if ($patch instanceof CardPaymentPatch) {
            // Allowed delta (plan "nested nulls omitted vs kept"): old `CardPaymentPatch::
            // jsonSerialize()` unconditionally emits `data: {cvv: <value>}` -- even when $cvv is
            // null (`$values['data'] = ['cvv' => $this->cvv];`, no stripNulls call at this level).
            // The generated `TransactionTokenUpdateRequestData::setCvv(null)` leaves `isset()`
            // false, so the typed model OMITS the key instead of emitting `{"cvv":null}` -- an
            // explicitly accepted delta, not a bug.
            $data = new TransactionTokenUpdateRequestData();
            if (isset($serialized['data']['cvv'])) {
                $data->setCvv((string) $serialized['data']['cvv']);
            }
            $request->setData($data);
        }

        return $request;
    }

    /**
     * Backs `Resources\Subscription\ScheduledPayment`'s `Resource::updateCall()`. Old
     * `ScheduledPayment` never declared a dedicated `patch()` either -- only the generic
     * inherited `Resource::update($updates)` -- same shape as `refundUpdate()`/`cancelUpdate()`
     * above, just against `SubscriptionPatchPaymentRequest`'s four known fields (`due_date`,
     * `is_paid`, `terminate_with_status`, `retry_interval`; no `metadata` field on this one at
     * all, unlike the others).
     */
    public static function scheduledPaymentUpdate(array $updates): SubscriptionPatchPaymentRequest
    {
        $request = new SubscriptionPatchPaymentRequest();
        $known = ['due_date', 'is_paid', 'terminate_with_status', 'retry_interval'];
        if (isset($updates['due_date'])) {
            $dueDate = $updates['due_date'];
            $request->setDueDate($dueDate instanceof DateTime ? $dueDate : new DateTime($dueDate));
        }
        if (isset($updates['is_paid'])) {
            $request->setIsPaid((bool) $updates['is_paid']);
        }
        if (isset($updates['terminate_with_status'])) {
            $request->setTerminateWithStatus($updates['terminate_with_status']);
        }
        if (isset($updates['retry_interval'])) {
            $request->setRetryInterval($updates['retry_interval']);
        }
        foreach ($updates as $key => $value) {
            if (!in_array($key, $known, true)) {
                $request->addAdditionalProperty((string) $key, $value);
            }
        }
        return $request;
    }

    private static function isAllScalar(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The generated `GenericMetadata`'s `addAdditionalProperty()` declares
     * `@mapsBy anyOf(string,float,bool,array[])` (verified against
     * `sdk/php/src/Models/GenericMetadata.php`), so arrays are accepted, recursively, alongside
     * scalars. This is a strict superset of `isAllScalar()` above -- used ONLY by metadata builders
     * backed by `GenericMetadata` (charge/refund/cancel/subscription create+update);
     * `TransactionTokenCreateRequestMetadata` (token creation) declares `@mapsBy
     * oneOf(string,bool,float)` instead (verified against the same generated tree) and must keep
     * using the stricter `isAllScalar()` / scalar-only passthrough-fallback path (see
     * `applyMetadata()`'s `$allowArrays` parameter). PHP cannot distinguish a JSON array from a
     * JSON object once both decode to a PHP array, so this treats every array value as acceptable
     * regardless of whether its keys are sequential or associative -- the honest behavior available
     * within PHP's own type system, not an approximation of `array[]`'s exact JSON-Schema
     * semantics.
     */
    private static function isMetadataTypeCompatible($values): bool
    {
        if (is_scalar($values)) {
            return true;
        }
        if (!is_array($values)) {
            return false;
        }
        foreach ($values as $value) {
            if (!self::isMetadataTypeCompatible($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param GenericMetadata|TransactionTokenCreateRequestMetadata $metadataObject
     * @param array<string, string> $knownSetters Map of wire key (e.g. 'univapay-name') to setter
     *        method name (e.g. 'setUnivapayName').
     */
    private static function populateMetadata($metadataObject, array $knownSetters, array $metadata)
    {
        foreach ($metadata as $key => $value) {
            if (isset($knownSetters[$key])) {
                $setter = $knownSetters[$key];
                $metadataObject->$setter((string) $value);
            } else {
                $metadataObject->addAdditionalProperty((string) $key, $value);
            }
        }
        return $metadataObject;
    }

    private static function buildGenericMetadata(array $metadata): GenericMetadata
    {
        return self::populateMetadata(new GenericMetadata(), [
            'order_id' => 'setOrderId',
            'univapay-name' => 'setUnivapayName',
            'univapay-phone-number' => 'setUnivapayPhoneNumber'
        ], $metadata);
    }

    private static function buildTokenCreateMetadata(array $metadata): TransactionTokenCreateRequestMetadata
    {
        return self::populateMetadata(new TransactionTokenCreateRequestMetadata(), [
            'univapay-reference-id' => 'setUnivapayReferenceId',
            'univapay-customer-id' => 'setUnivapayCustomerId',
            'univapay-name' => 'setUnivapayName',
            'univapay-phone-number' => 'setUnivapayPhoneNumber'
        ], $metadata);
    }

    /**
     * @param object $request The root request model (has a 'metadata' property already known to
     *        `addAdditionalProperty()`, so the passthrough branch below must bypass it).
     * @param string $setterMethod e.g. 'setMetadata'.
     * @param callable $typedBuilder array -> typed metadata object, invoked only when every value
     *        is type-compatible with the target metadata model (see `$allowArrays`).
     * @param bool $allowArrays Whether the target metadata model accepts array values in addition
     *        to scalars -- true for `GenericMetadata`-backed builders (see
     *        `isMetadataTypeCompatible()`'s doc), false for `TransactionTokenCreateRequestMetadata`
     *        (token creation, scalar-only).
     */
    private static function applyMetadata(
        $request,
        string $setterMethod,
        callable $typedBuilder,
        ?array $metadata,
        bool $allowArrays = true
    ): void {
        if ($metadata === null) {
            return;
        }
        $isTypeCompatible = $allowArrays ? self::isMetadataTypeCompatible($metadata) : self::isAllScalar($metadata);
        if ($isTypeCompatible) {
            $request->$setterMethod($typedBuilder($metadata));
            return;
        }
        // Genuine remaining spec gap: the target metadata model still rejects this shape outright
        // (e.g. a nested object/associative-array value even with GenericMetadata's array[]
        // support, or any non-scalar value at all for token metadata). Passthrough the raw
        // old-SDK metadata array into the ROOT request's private `$metadata` slot directly -- see
        // class doc for why `addAdditionalProperty()` isn't usable here.
        self::setPrivateProperty($request, 'metadata', $metadata);
    }

    /**
     * Bypasses a generated model's typed setter to write directly to one of its private
     * properties, for the two cases (3DS passthrough, non-scalar metadata passthrough) where the
     * generated setter's type hint (or `addAdditionalProperty()`'s conflicting-property-name
     * guard) makes the public API unusable, but the model's own `jsonSerialize()` will still emit
     * whatever raw value ends up in the slot with no further validation. `ReflectionProperty::
     * setAccessible()` is available and behaves identically across the whole PHP 7.2-8.3 support
     * matrix (no deprecation applies to this method, only to `ReflectionMethod::setAccessible()`
     * on newer PHPs, which this class never calls).
     *
     * @param mixed $value
     */
    private static function setPrivateProperty($object, string $property, $value): void
    {
        $reflection = new ReflectionProperty(get_class($object), $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }
}
