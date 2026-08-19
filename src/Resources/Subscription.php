<?php

namespace Univapay\Compat\Resources;

use DateInterval;
use DateTime;
use DateTimeZone;
use Money\Currency;
use Money\Money;
use UnivaPay\Models\Subscription as GeneratedSubscription;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\Field;
use Univapay\Compat\Enums\InstallmentPlanType;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\Period;
use Univapay\Compat\Enums\Reason;
use Univapay\Compat\Enums\SubscriptionPlanType;
use Univapay\Compat\Enums\SubscriptionStatus;
use Univapay\Compat\Enums\ThreeDSMode;
use Univapay\Compat\Errors\UnivapayLogicError;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\Mixins\GetCharges;
use Univapay\Compat\Resources\Mixins\GetScheduledPayments;
use Univapay\Compat\Resources\Subscription\InstallmentPlan;
use Univapay\Compat\Resources\Subscription\ScheduledPayment;
use Univapay\Compat\Resources\Subscription\ScheduleSettings;
use Univapay\Compat\Resources\Subscription\SubscriptionPlan;
use Univapay\Compat\Support\ListDispatcher;
use Univapay\Compat\Support\RequestModelFactory;
use Univapay\Compat\Support\TypedHydrator;
use Univapay\Compat\Support\TypedResult;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\Subscription` (namespace lines + transport plumbing only --
 * public props, guards (`patch()`'s status-transition switch, `isEditable()`/`isTokenPatchable()`/
 * `isTerminal()`), and `isSubscribable()` are otherwise verbatim). Property order (storeId ..
 * threeDS) already matches the old constructor.
 *
 * ## patch(): the 9-arg spec-gap seam
 *
 * `RequestModelFactory::subscriptionPatch()` builds the outbound `SubscriptionUpdateRequest` AFTER
 * every guard below has passed -- see that method's own class
 * doc for the genuine coverage gap it works around (the generated update model has no
 * `period`/`cyclical_period`/`subscription_plan`/`installment_plan`/`initial_amount` field, all
 * bridged via `addAdditionalProperty()` since none conflict with its known property names).
 *
 * ## cancel(): DELETE, no hydration
 *
 * Old `cancel()` called `RequesterUtils::executeDelete($context)` -- no `$parser`, so nothing was
 * ever hydrated; this mirrors `TransactionToken::deactivate()`'s pattern of simply returning
 * whatever `Support\ApiCaller::call()` decodes (`true` for an empty 204 body, matching old
 * `HttpUtils::checkResponse()` parity).
 *
 * ## Pollable: terminal semantics not independently verified
 *
 * `pollableStatuses()` below is transcribed byte-for-byte from the old SDK and pinned against
 * `tests/Fixtures/PollableStatusMaps::subscription()` (see `tests/Unit/Resources/
 * PollableTransitionMapsTest.php`). Whether these are still the correct terminal transitions
 * against the current backend has not been independently confirmed.
 */
class Subscription extends Resource
{
    use Jsonable;
    use Pollable;
    use GetCharges, GetScheduledPayments {
        GetCharges::validate insteadof GetScheduledPayments;
    }

    public $storeId;
    public $transactionTokenId;
    public $currency;
    public $amount;
    public $amountFormatted;
    public $period;
    public $cyclicalPeriod;
    public $scheduleSettings;
    public $paymentsLeft;
    public $status;
    public $metadata;
    public $mode;
    public $createdOn;
    public $amountLeft;
    public $amountLeftFormatted;
    public $initialAmount;
    public $initialAmountFormatted;
    public $nextPayment;
    public $subscriptionPlan;
    public $installmentPlan;
    public $firstChargeAuthorizationOnly;
    public $firstChargeCaptureAfter;
    public $threeDS;

    /**
     * @param mixed $id
     * @param mixed $storeId
     * @param mixed $transactionTokenId
     * @param mixed $amountFormatted
     * @param mixed $paymentsLeft
     * @param mixed $metadata
     * @param mixed $amountLeftFormatted
     * @param mixed $initialAmountFormatted
     * @param mixed $firstChargeAuthorizationOnly
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $storeId,
        $transactionTokenId,
        Currency $currency,
        Money $amount,
        $amountFormatted,
        ?Period $period,
        ?DateInterval $cyclicalPeriod,
        ScheduleSettings $scheduleSettings,
        $paymentsLeft,
        SubscriptionStatus $status,
        $metadata,
        AppTokenMode $mode,
        DateTime $createdOn,
        ?Money $amountLeft,
        $amountLeftFormatted,
        ?Money $initialAmount = null,
        $initialAmountFormatted = null,
        ?ScheduledPayment $nextPayment = null,
        ?SubscriptionPlan $subscriptionPlan = null,
        ?InstallmentPlan $installmentPlan = null,
        $firstChargeAuthorizationOnly = null,
        ?DateInterval $firstChargeCaptureAfter = null,
        ?PaymentThreeDS $threeDS = null,
        $context = null
    ) {
        parent::__construct($id, $context);
        $this->storeId = $storeId;
        $this->transactionTokenId = $transactionTokenId;
        $this->currency = $currency;
        $this->amount = $amount;
        $this->amountFormatted = $amountFormatted;
        $this->period = $period;
        $this->cyclicalPeriod = $cyclicalPeriod;
        $this->initialAmount = $initialAmount;
        $this->initialAmountFormatted = $initialAmountFormatted;
        $this->scheduleSettings = $scheduleSettings;
        $this->nextPayment = $nextPayment;
        $this->paymentsLeft = $paymentsLeft;
        $this->amountLeft = $amountLeft;
        $this->amountLeftFormatted = $amountLeftFormatted;
        $this->status = $status;
        $this->metadata = $metadata;
        $this->mode = $mode;
        $this->createdOn = $createdOn;
        $this->subscriptionPlan = $subscriptionPlan;
        $this->installmentPlan = $installmentPlan;
        $this->firstChargeAuthorizationOnly = $firstChargeAuthorizationOnly;
        $this->firstChargeCaptureAfter = $firstChargeCaptureAfter;
        $this->threeDS = $threeDS;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('currency', true, FormatterUtils::of('getCurrency'))
            ->upsert('amount', true, FormatterUtils::getMoney('currency'))
            ->upsert('period', false, FormatterUtils::getTypedEnum(Period::class))
            ->upsert('cyclical_period', false, FormatterUtils::of('getDateInterval'))
            ->upsert('initial_amount', false, FormatterUtils::getMoney('currency'))
            ->upsert('schedule_settings', true, ScheduleSettings::getSchema()->getParser())
            ->upsert('amount_left', false, FormatterUtils::getMoney('currency'))
            ->upsert('status', true, FormatterUtils::getTypedEnum(SubscriptionStatus::class))
            ->upsert('mode', true, FormatterUtils::getTypedEnum(AppTokenMode::class))
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'))
            ->upsert('next_payment', false, ScheduledPayment::getSchema()->getParser())
            ->upsert('subscription_plan', false, SubscriptionPlan::getSchema()->getParser())
            ->upsert('installment_plan', false, InstallmentPlan::getSchema()->getParser())
            ->upsert('first_charge_capture_after', false, FormatterUtils::of('getDateInterval'))
            ->upsert('three_ds', false, PaymentThreeDS::getSchema()->getParser());
    }

    /**
     * Typed-first hydration entry point for `Support\TypedHydrator`. `univapay/client-sdk` 1.2.0
     * closed the spec gap that kept this resource raw-primary (see docs/ARCHITECTURE.md): the
     * generated `UnivaPay\Models\Subscription` now carries `three_ds`, `cyclical_period`,
     * `subscription_plan`, `installment_plan`, `amount_left`/`amount_left_formatted` -- every
     * field this schema reads now has a typed counterpart, with two exceptions patched from raw:
     *
     * - `metadata`/`payments_left`: `metadata` is the usual GenericMetadata-is-always-raw
     *   treatment (see `Charge`'s own doc). `payments_left` is a PRE-EXISTING, unrelated mismatch:
     *   this class's own auto-derived schema has always read `payments_left`, but the generated
     *   model's equivalent field is named `cycles_left` -- a different key, not just a differently
     *   cased one. Reading it from $body here preserves the exact existing behavior either way:
     *   present, it flows through unchanged; absent (e.g. if the real API has since renamed it to
     *   `cycles_left`), it stays null on both paths, same as it always has. `cycles_left` itself
     *   is not read anywhere -- this class has no property to receive it, same as `charge_id`
     *   (also new in 1.2.0, also has no compat property).
     * - `next_payment`: parsed from $body's raw sub-object via `ScheduledPayment`'s existing raw
     *   parser, not `SubscriptionNextPayment`'s typed getters. This is NOT a missing-typed-field
     *   gap (every field `ScheduledPayment` reads has a typed counterpart on
     *   `SubscriptionNextPayment`) -- `ScheduledPayment`'s own constructor does its OWN raw-string
     *   parsing (`new DateTimeZone($zoneId)`, `date_create($dueDate)->setTimezone(...)`, `new
     *   Currency($currency)`, `new Money($amount, $currency)`, `$createdOn ?? 'now'`), unlike
     *   every other nested type here, which goes through `FormatterUtils`. Feeding it the typed
     *   getters' already-parsed `\DateTime`/int values would require reformatting them back to
     *   strings first, for no real benefit -- `ScheduledPayment` itself is not flipped as a
     *   resource by this change.
     *
     * `three_ds`'s typed source, `SubscriptionThreeDs`, has no MPI fields (`mode`/
     * `redirect_endpoint`/`redirect_id` only) -- same gap `Charge`'s `ChargeThreeDs` has (see its
     * own doc). Unlike `Charge`, `redirect_id` IS present here, so only `threeDSMPI` is missing;
     * since MPI data is request-only (never returned by the server -- see `PaymentThreeDS`'s own
     * class doc), it is hardcoded null rather than patched from raw.
     *
     * Declines (null) when a required field (`currency`/`amount`/`schedule_settings`'s own
     * `zone_id`/`status`/`mode`/`created_on`) is missing from the typed model.
     *
     * @param mixed $typed
     * @param array $body
     * @param \Univapay\Compat\Support\CompatContext|null $context
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body, $context)
    {
        if (!($typed instanceof GeneratedSubscription)) {
            return null;
        }
        $currencyValue = $typed->getCurrency();
        $amount = $typed->getAmount();
        $status = $typed->getStatus();
        $mode = $typed->getMode();
        $createdOn = $typed->getCreatedOn();
        $scheduleSettingsTyped = $typed->getScheduleSettings();
        if (
            $currencyValue === null || $amount === null || $status === null || $mode === null
            || $createdOn === null || $scheduleSettingsTyped === null
        ) {
            return null;
        }
        $scheduleSettings = self::scheduleSettingsFromTyped($scheduleSettingsTyped);
        if ($scheduleSettings === null) {
            return null;
        }

        $currency = new Currency($currencyValue);

        $periodValue = $typed->getPeriod();
        $cyclicalPeriodValue = $typed->getCyclicalPeriod();
        $initialAmountValue = $typed->getInitialAmount();
        $amountLeftValue = $typed->getAmountLeft();
        $firstChargeCaptureAfterValue = $typed->getFirstChargeCaptureAfter();

        $nextPaymentTyped = $typed->getNextPayment();
        $nextPayment = null;
        if ($nextPaymentTyped !== null && isset($body['next_payment']) && is_array($body['next_payment'])) {
            $nextPayment = ScheduledPayment::getSchema()->parse($body['next_payment'], [$context]);
        }

        $subscriptionPlanTyped = $typed->getSubscriptionPlan();
        $subscriptionPlan = $subscriptionPlanTyped !== null
            ? self::subscriptionPlanFromTyped($subscriptionPlanTyped, $currency)
            : null;

        $installmentPlanTyped = $typed->getInstallmentPlan();
        $installmentPlan = $installmentPlanTyped !== null
            ? self::installmentPlanFromTyped($installmentPlanTyped)
            : null;

        $threeDsTyped = $typed->getThreeDs();
        $threeDS = $threeDsTyped !== null ? self::threeDSFromTyped($threeDsTyped) : null;

        return new self(
            $typed->getId(),
            $typed->getStoreId(),
            $typed->getTransactionTokenId(),
            $currency,
            new Money($amount, $currency),
            $typed->getAmountFormatted(),
            $periodValue !== null ? Period::fromValue($periodValue) : null,
            $cyclicalPeriodValue !== null ? new DateInterval($cyclicalPeriodValue) : null,
            $scheduleSettings,
            array_key_exists('payments_left', $body) ? $body['payments_left'] : null,
            SubscriptionStatus::fromValue($status),
            array_key_exists('metadata', $body) ? $body['metadata'] : null,
            AppTokenMode::fromValue($mode),
            $createdOn,
            $amountLeftValue !== null ? new Money($amountLeftValue, $currency) : null,
            $typed->getAmountLeftFormatted(),
            $initialAmountValue !== null ? new Money($initialAmountValue, $currency) : null,
            $typed->getInitialAmountFormatted(),
            $nextPayment,
            $subscriptionPlan,
            $installmentPlan,
            $typed->getFirstChargeAuthorizationOnly(),
            $firstChargeCaptureAfterValue !== null ? new DateInterval($firstChargeCaptureAfterValue) : null,
            $threeDS,
            $context
        );
    }

    /** @return ScheduleSettings|null Null if `zone_id` (required) is missing. */
    private static function scheduleSettingsFromTyped($typed)
    {
        $zoneIdValue = $typed->getZoneId();
        if ($zoneIdValue === null) {
            return null;
        }
        $retryIntervalValue = $typed->getRetryInterval();
        return new ScheduleSettings(
            $typed->getStartOn(),
            new DateTimeZone($zoneIdValue),
            $typed->getPreserveEndOfMonth(),
            $retryIntervalValue !== null ? new DateInterval($retryIntervalValue) : null
        );
    }

    /** @return SubscriptionPlan|null Null if `plan_type` (required) is missing. */
    private static function subscriptionPlanFromTyped($typed, Currency $currency)
    {
        $planTypeValue = $typed->getPlanType();
        if ($planTypeValue === null) {
            return null;
        }
        $fixedCycleAmountValue = $typed->getFixedCycleAmount();
        return new SubscriptionPlan(
            SubscriptionPlanType::fromValue($planTypeValue),
            $typed->getFixedCycles(),
            $fixedCycleAmountValue !== null ? new Money($fixedCycleAmountValue, $currency) : null
        );
    }

    /** @return InstallmentPlan|null Null if `plan_type` (required) is missing. */
    private static function installmentPlanFromTyped($typed)
    {
        $planTypeValue = $typed->getPlanType();
        if ($planTypeValue === null) {
            return null;
        }
        return new InstallmentPlan(InstallmentPlanType::fromValue($planTypeValue), $typed->getFixedCycles());
    }

    /**
     * @return PaymentThreeDS `threeDSMPI` is always null -- request-only data, never present in a
     *         response (see this method's caller's own doc).
     */
    private static function threeDSFromTyped($typed)
    {
        return new PaymentThreeDS(
            $typed->getRedirectEndpoint(),
            ThreeDSMode::fromValue($typed->getMode()),
            null,
            $typed->getRedirectId()
        );
    }

    protected function pollableStatuses()
    {
        return [
            (string) SubscriptionStatus::UNVERIFIED() => array_diff(
                SubscriptionStatus::findValues(),
                [SubscriptionStatus::UNVERIFIED()]
            ),
            (string) SubscriptionStatus::AUTHORIZED() => array_diff(
                SubscriptionStatus::findValues(),
                [SubscriptionStatus::UNVERIFIED(), SubscriptionStatus::AUTHORIZED()]
            )
        ];
    }

    // --- Resource wiring (fetch/update/awaitResult) ----------------------------------------------

    protected function fetchCall()
    {
        $bridge = $this->context->bridge();
        $subscriptions = $bridge->subscriptions();
        return $bridge->caller()->callTyped(
            function () use ($subscriptions) {
                return $subscriptions->getSubscription($this->storeId, $this->id);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/subscriptions/{$this->id}"
        );
    }

    /**
     * @param \UnivaPay\Models\SubscriptionUpdateRequest $updates Already built by `patch()` below
     *        (guards run there, request-building happens in `RequestModelFactory::
     *        subscriptionPatch()`) -- same "build in the public method, execute in updateCall()"
     *        split `TransactionToken::patch()`/`updateCall()` uses.
     */
    protected function updateCall($updates)
    {
        $bridge = $this->context->bridge();
        $subscriptions = $bridge->subscriptions();
        return $bridge->caller()->callTyped(
            function ($idempotencyKey) use ($subscriptions, $updates) {
                return $subscriptions->updateSubscription($this->storeId, $this->id, $idempotencyKey, $updates);
            },
            $bridge->handlers(),
            "PATCH /stores/{$this->storeId}/subscriptions/{$this->id}"
        );
    }

    /**
     * @return static
     */
    protected function fetchWithPolling()
    {
        $bridge = $this->context->bridge();
        $subscriptions = $bridge->subscriptions();
        $result = $bridge->caller()->callTyped(
            function () use ($subscriptions) {
                return $subscriptions->getSubscription($this->storeId, $this->id, true);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/subscriptions/{$this->id}?polling=true"
        );
        return $this->resolveHydration($result);
    }

    /**
     * @param mixed $transactionTokenId
     * @return static
     */
    public function patch(
        $transactionTokenId = null,
        ?Money $initialAmount = null,
        ?Period $period = null,
        ?ScheduleSettings $scheduleSettings = null,
        ?SubscriptionStatus $status = null,
        ?array $metadata = null,
        ?SubscriptionPlan $subscriptionPlan = null,
        ?InstallmentPlan $installmentPlan = null,
        ?DateInterval $cyclicalPeriod = null
    ) {
        if (SubscriptionStatus::CANCELED() == $this->status) {
            throw new UnivapayLogicError(Reason::CANNOT_CHANGE_CANCELED_SUBSCRIPTION());
        }
        if (isset($transactionTokenId) && !$this->isTokenPatchable()) {
            throw new UnivapayLogicError(Reason::CANNOT_CHANGE_TOKEN());
        }
        if (isset($initialAmount) && !$this->isEditable() && $initialAmount->isNegative()) {
            throw new UnivapayValidationError(Field::INITIAL_AMOUNT(), Reason::INVALID_FORMAT());
        }
        if (isset($period) && !$this->isEditable()) {
            throw new UnivapayLogicError(Reason::CANNOT_SET_AFTER_SUBSCRIPTION_STARTED());
        }
        if (isset($cyclicalPeriod) && !$this->isEditable()) {
            throw new UnivapayLogicError(Reason::CANNOT_SET_AFTER_SUBSCRIPTION_STARTED());
        }
        if (isset($status)) {
            switch ($this->status) {
                case SubscriptionStatus::UNPAID():
                case SubscriptionStatus::CURRENT():
                    if (SubscriptionStatus::SUSPENDED() !== $status) {
                        throw new UnivapayValidationError(Field::STATUS(), Reason::FORBIDDEN_PARAMETER());
                    }
                    break;
                case SubscriptionStatus::SUSPENDED():
                    if (SubscriptionStatus::UNPAID() !== $status) {
                        throw new UnivapayValidationError(Field::STATUS(), Reason::FORBIDDEN_PARAMETER());
                    }
                    break;
                default:
                    throw new UnivapayValidationError(Field::STATUS(), Reason::FORBIDDEN_PARAMETER());
            }
        }
        if ((isset($subscriptionPlan) || isset($installmentPlan)) && !$this->isEditable()) {
            throw new UnivapayLogicError(Reason::PLAN_ALREADY_SET());
        }

        // Old dead-code `if (isset($money)) { $payload += $money->jsonSerialize(); }` branch
        // (`$money` is never assigned anywhere in old `patch()`) is deliberately NOT reproduced --
        // see RequestModelFactory::subscriptionPatch()'s class doc.
        $request = RequestModelFactory::subscriptionPatch(
            $transactionTokenId,
            $initialAmount,
            $period,
            $scheduleSettings,
            $status,
            $metadata,
            $subscriptionPlan,
            $installmentPlan,
            $cyclicalPeriod
        );

        return $this->update($request);
    }

    /**
     * @return mixed `true` on an empty 204 body -- see class doc.
     */
    public function cancel()
    {
        if ($this->isTerminal()) {
            throw new UnivapayLogicError(Reason::SUBSCRIPTION_ALREADY_ENDED());
        }
        $bridge = $this->context->bridge();
        $subscriptions = $bridge->subscriptions();
        return $bridge->caller()->call(
            function () use ($subscriptions) {
                return $subscriptions->cancelSubscription($this->storeId, $this->id);
            },
            $bridge->handlers(),
            "DELETE /stores/{$this->storeId}/subscriptions/{$this->id}"
        );
    }

    public function isEditable()
    {
        switch ($this->status) {
            case SubscriptionStatus::UNVERIFIED():
            case SubscriptionStatus::UNCONFIRMED():
                return true;
            default:
                return false;
        }
    }

    public function isProcessing()
    {
        switch ($this->status) {
            case SubscriptionStatus::UNPAID():
            case SubscriptionStatus::CURRENT():
            case SubscriptionStatus::SUSPENDED():
                return true;
            default:
                return false;
        }
    }

    public function isTokenPatchable()
    {
        switch ($this->status) {
            case SubscriptionStatus::UNCONFIRMED():
            case SubscriptionStatus::UNPAID():
            case SubscriptionStatus::CURRENT():
            case SubscriptionStatus::SUSPENDED():
                return true;
            default:
                return false;
        }
    }

    public function isTerminal()
    {
        switch ($this->status) {
            case SubscriptionStatus::CANCELED():
            case SubscriptionStatus::COMPLETED():
                return true;
            default:
                return false;
        }
    }

    public static function isSubscribable(PaymentType $paymentType)
    {
        return PaymentType::CARD() === $paymentType ||
            PaymentType::KONBINI() === $paymentType ||
            PaymentType::APPLE_PAY() === $paymentType;
    }

    // --- GetCharges/GetScheduledPayments mixin hooks ---------------------------------------------

    protected function listChargesPage(array $query)
    {
        $bridge = $this->context->bridge();
        return ListDispatcher::listSubscriptionCharges(
            $bridge,
            $this->storeId,
            $this->id,
            $query,
            function ($raw, $typed = null) {
                return TypedHydrator::resolve(Charge::class, new TypedResult($raw, $typed, false), $this->context);
            }
        );
    }

    protected function listScheduledPaymentsPage(array $query)
    {
        $bridge = $this->context->bridge();
        return ListDispatcher::listSubscriptionPayments(
            $bridge,
            $this->storeId,
            $this->id,
            $query,
            function ($raw) {
                return ScheduledPayment::getSchema()->parse($raw, [$this->context]);
            }
        );
    }
}
