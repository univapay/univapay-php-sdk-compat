<?php

namespace Univapay\Compat\Resources\Subscription;

use DateTimeZone;
use Money\Currency;
use Money\Money;
use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Resources\Charge;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Resources\Mixins\GetCharges;
use Univapay\Compat\Resources\Resource;
use Univapay\Compat\Support\ListDispatcher;
use Univapay\Compat\Support\RequestModelFactory;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\Subscription\ScheduledPayment` (namespace lines + transport
 * plumbing only). Property order (subscriptionId .. createdOn) already matches the old
 * constructor; the `zoneId`/`dueDate`/`currency`/`amount` hydration logic in the constructor body
 * (not a `Jsonable` schema formatter, unlike every other resource in this repo) is copied verbatim
 * -- old-SDK quirk, not something to "modernize" into `initSchema()` upserts.
 *
 * No `storeId` property exists on this class at all (old SDK never added one) -- every
 * HTTP-touching method below reads it off `$this->context->storeId` instead (populated because
 * this resource is always hydrated with the SAME `CompatContext` its owning `Subscription`
 * carries: either via `Subscription::initSchema()`'s `next_payment` nested-schema parse, which
 * propagates the parent's own `$additionalArgs` down through `Utility\Json\JsonSchema::
 * getValues()`, or via `Subscription::listScheduledPaymentsPage()`'s item parser, which passes
 * `$this->context` explicitly -- see `Support\ListDispatcher`'s class doc on raw-body hydration).
 *
 * ## listCharges(): the narrow-override seam
 *
 * Old `ScheduledPayment` attached the FULL `Mixins\GetCharges` (same trait `Charge` uses) but
 * renamed its public `listCharges()` to a private `fullListCharges()` and declared its OWN narrow
 * `listCharges($cursor, $limit, $cursorDirection)` that forwards to it with every OTHER of
 * `GetCharges::listCharges()`'s ~20 params fixed to null -- because the real endpoint underneath
 * (`SubscriptionsApi::listChargesForSubscriptionPayment()`, mapped via
 * `Support\ListDispatcher::listChargesForSubscriptionPayment()`) only ever accepts
 * `limit`/`cursor`/`cursorDirection`, no other filters at all. This is a direct port of
 * that exact `use ... { listCharges as private fullListCharges; }` trick, not a simplification --
 * `listChargesPage()` (the abstract hook `GetCharges` requires) is what actually reaches the
 * dispatcher; `fullListCharges()`'s other ~17 always-null arguments simply never survive
 * `FunctionalUtils::stripNulls()` into the query dispatcher sees.
 */
class ScheduledPayment extends Resource
{
    use Jsonable;
    use GetCharges {
        listCharges as private fullListCharges;
    }

    public $subscriptionId;
    public $dueDate;
    public $zoneId;
    public $currency;
    public $amount;
    public $amountFormatted;
    public $isPaid;
    public $isLastPayment;
    public $createdOn;

    /**
     * @param mixed $id
     * @param mixed $subscriptionId
     * @param mixed $dueDate
     * @param mixed $zoneId
     * @param mixed $currency
     * @param mixed $amount
     * @param mixed $amountFormatted
     * @param mixed $isPaid
     * @param mixed $isLastPayment
     * @param mixed $createdOn
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $subscriptionId,
        $dueDate,
        $zoneId,
        $currency,
        $amount,
        $amountFormatted,
        $isPaid,
        $isLastPayment,
        $createdOn,
        $context = null
    ) {
        parent::__construct($id, $context);
        $this->subscriptionId = $subscriptionId;
        $this->zoneId = new DateTimeZone($zoneId);
        $this->dueDate = date_create($dueDate)->setTimezone($this->zoneId);
        $this->currency = new Currency($currency);
        $this->amount = new Money($amount, $this->currency);
        $this->amountFormatted = $amountFormatted;
        $this->isPaid = $isPaid;
        $this->isLastPayment = $isLastPayment;
        // add default value for createdOn, `/subscriptions/simulate_plan` does not return createdOn
        $createdOn = $createdOn ?? 'now';
        $this->createdOn = date_create($createdOn);
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }

    // --- Resource wiring (fetch/update) ----------------------------------------------------------

    protected function fetchCall()
    {
        $bridge = $this->context->bridge();
        $subscriptions = $bridge->subscriptions();
        $storeId = $this->context->storeId;
        return $bridge->caller()->call(
            function () use ($subscriptions, $storeId) {
                return $subscriptions->getSubscriptionPayment($storeId, $this->subscriptionId, $this->id);
            },
            $bridge->handlers(),
            "GET /stores/$storeId/subscriptions/{$this->subscriptionId}/payments/{$this->id}"
        );
    }

    protected function updateCall($updates)
    {
        $request = RequestModelFactory::scheduledPaymentUpdate($updates);
        $bridge = $this->context->bridge();
        $subscriptions = $bridge->subscriptions();
        $storeId = $this->context->storeId;
        return $bridge->caller()->call(
            function ($idempotencyKey) use ($subscriptions, $storeId, $request) {
                return $subscriptions->updateSubscriptionPayment(
                    $storeId,
                    $this->subscriptionId,
                    $this->id,
                    $request,
                    $idempotencyKey
                );
            },
            $bridge->handlers(),
            "PATCH /stores/$storeId/subscriptions/{$this->subscriptionId}/payments/{$this->id}"
        );
    }

    // --- GetCharges mixin hook + narrow override -------------------------------------------------

    protected function listChargesPage(array $query)
    {
        $bridge = $this->context->bridge();
        return ListDispatcher::listChargesForSubscriptionPayment(
            $bridge,
            $this->context->storeId,
            $this->subscriptionId,
            $this->id,
            $query,
            function ($raw) {
                return Charge::getSchema()->parse($raw, [$this->context]);
            }
        );
    }

    /**
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return \Univapay\Compat\Resources\Paginated
     */
    public function listCharges(
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        return $this->fullListCharges(
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $cursor,
            $limit,
            $cursorDirection
        );
    }
}
