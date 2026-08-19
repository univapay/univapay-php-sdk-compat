<?php

namespace Univapay\Compat\Resources;

use Money\Currency;
use Money\Money;
use UnivaPay\Models\Refund as GeneratedRefund;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\RefundReason;
use Univapay\Compat\Enums\RefundStatus;
use Univapay\Compat\Support\RequestModelFactory;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\Refund` (namespace lines + transport plumbing only). Property
 * order (storeId .. metadata) already matches the old constructor.
 *
 * Old `Refund` never declared its own `patch()`/dedicated update method -- only the generic
 * inherited `Resource::update($updates)`/`fetch()` (built here against
 * `RefundsApi::getRefund()`/`updateRefund()`, mapped through `Support\RequestModelFactory::
 * refundUpdate()`). `Charge::createRefund()` hydrates this class via `callAndHydrate()`; this
 * class's own `fetchCall()`/`updateCall()`/`fetchWithPolling()` cover the "already have a Refund,
 * refetch/patch/poll IT" cases.
 */
class Refund extends Resource
{
    use Jsonable;
    use Pollable;

    public $storeId;
    public $chargeId;
    public $status;
    public $currency;
    public $amount;
    public $amountFormatted;
    public $mode;
    public $createdOn;
    public $reason;
    public $message;
    public $error;
    public $metadata;

    /**
     * @param mixed $id
     * @param mixed $storeId
     * @param mixed $chargeId
     * @param mixed $amountFormatted
     * @param mixed $reason
     * @param mixed $message
     * @param mixed $error
     * @param mixed $metadata
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $storeId,
        $chargeId,
        $status,
        $currency,
        $amount,
        $amountFormatted,
        $mode,
        $createdOn,
        $reason = null,
        $message = null,
        $error = null,
        $metadata = null,
        $context = null
    ) {
        parent::__construct($id, $context);
        $this->storeId = $storeId;
        $this->chargeId = $chargeId;
        $this->status = $status;
        $this->currency = $currency;
        $this->amount = $amount;
        $this->amountFormatted = $amountFormatted;
        $this->reason = $reason;
        $this->message = $message;
        $this->error = $error;
        $this->metadata = $metadata;
        $this->mode = $mode;
        $this->createdOn = $createdOn;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('status', true, FormatterUtils::getTypedEnum(RefundStatus::class))
            ->upsert('currency', true, FormatterUtils::of('getCurrency'))
            ->upsert('amount', true, FormatterUtils::getMoney('currency'))
            ->upsert('reason', false, FormatterUtils::getTypedEnum(RefundReason::class))
            ->upsert('mode', true, FormatterUtils::getTypedEnum(AppTokenMode::class))
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'));
    }

    /**
     * Typed-first hydration entry point for `Support\TypedHydrator` -- see `Charge::
     * hydrateFromTyped()`'s doc for the general shape. `error`/`metadata` are read from $body (this
     * response's raw decoded body), not the typed `PaymentError`/`GenericMetadata` models: compat
     * has always stored these as the raw decoded value verbatim (see docs/ARCHITECTURE.md's
     * GenericMetadata note). Every other field is a clean 1:1 match against the generated SDK's
     * `UnivaPay\Models\Refund` -- no spec gap.
     *
     * @param mixed $typed
     * @param array $body
     * @param \Univapay\Compat\Support\CompatContext|null $context
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body, $context)
    {
        if (!($typed instanceof GeneratedRefund)) {
            return null;
        }
        if (
            $typed->getStatus() === null
            || $typed->getCurrency() === null
            || $typed->getAmount() === null
            || $typed->getMode() === null
            || $typed->getCreatedOn() === null
        ) {
            return null;
        }

        $currency = new Currency($typed->getCurrency());

        return new self(
            $typed->getId(),
            $typed->getStoreId(),
            $typed->getChargeId(),
            RefundStatus::fromValue($typed->getStatus()),
            $currency,
            new Money($typed->getAmount(), $currency),
            $typed->getAmountFormatted(),
            AppTokenMode::fromValue($typed->getMode()),
            $typed->getCreatedOn(),
            RefundReason::fromValue($typed->getReason()),
            $typed->getMessage(),
            array_key_exists('error', $body) ? $body['error'] : null,
            array_key_exists('metadata', $body) ? $body['metadata'] : null,
            $context
        );
    }

    protected function pollableStatuses()
    {
        return [(string) RefundStatus::PENDING() => array_diff(RefundStatus::findValues(), [RefundStatus::PENDING()])];
    }

    protected function nativeFetchEquivalent(): string
    {
        return 'RefundsApi::getRefund()';
    }

    protected function nativeUpdateEquivalent(): string
    {
        return 'RefundsApi::updateRefund()';
    }

    protected function nativePollEquivalent(): string
    {
        return 'RefundsApi::pollRefund()';
    }

    protected function fetchCall()
    {
        $bridge = $this->context->bridge();
        $refunds = $bridge->refunds();
        return $bridge->caller()->callTyped(
            function () use ($refunds) {
                return $refunds->getRefund($this->storeId, $this->chargeId, $this->id);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/charges/{$this->chargeId}/refunds/{$this->id}"
        );
    }

    protected function updateCall($updates)
    {
        $request = RequestModelFactory::refundUpdate($updates);
        $bridge = $this->context->bridge();
        $refunds = $bridge->refunds();
        return $bridge->caller()->callTyped(
            function ($idempotencyKey) use ($refunds, $request) {
                return $refunds->updateRefund($this->storeId, $this->chargeId, $this->id, $request, $idempotencyKey);
            },
            $bridge->handlers(),
            "PATCH /stores/{$this->storeId}/charges/{$this->chargeId}/refunds/{$this->id}"
        );
    }

    /**
     * @return static
     */
    protected function fetchWithPolling()
    {
        $bridge = $this->context->bridge();
        $refunds = $bridge->refunds();
        $result = $bridge->caller()->callTyped(
            function () use ($refunds) {
                return $refunds->getRefund($this->storeId, $this->chargeId, $this->id, true);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/charges/{$this->chargeId}/refunds/{$this->id}?polling=true"
        );
        return $this->resolveHydration($result);
    }
}
