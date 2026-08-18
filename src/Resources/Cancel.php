<?php

namespace Univapay\Compat\Resources;

use UnivaPay\Models\Cancel as GeneratedCancel;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\CancelStatus;
use Univapay\Compat\Support\RequestModelFactory;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\Cancel` (namespace lines + transport plumbing only). Property
 * order (chargeId, storeId, status, error, metadata, mode, createdOn) already matches the old
 * constructor -- note `chargeId` precedes `storeId` here, the reverse of `Refund`'s order; this is
 * the old SDK's own declared order, preserved exactly since `Jsonable::getSchema()`'s reflection-
 * based `parse()` depends on it.
 *
 * Old `Cancel` overrode `getIdContext()` to build its full path from `storeId`/`chargeId`/`id`
 * explicitly rather than relying on inherited context -- this class's `fetchCall()`/`updateCall()`
 * achieve the same explicitness by simply passing those three wire properties straight to the
 * generated controller, which needs nothing else. `Charge::cancel()` hydrates this class via
 * `callAndHydrate()`; this class's own methods cover "already have a Cancel, refetch/patch/poll IT".
 */
class Cancel extends Resource
{
    use Jsonable;
    use Pollable;

    public $chargeId;
    public $storeId;
    public $status;
    public $error;
    public $metadata;
    public $mode;
    public $createdOn;

    /**
     * @param mixed $id
     * @param mixed $chargeId
     * @param mixed $storeId
     * @param mixed $error
     * @param mixed $metadata
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $chargeId,
        $storeId,
        $status,
        $error,
        $metadata,
        $mode,
        $createdOn,
        $context = null
    ) {
        parent::__construct($id, $context);
        $this->chargeId = $chargeId;
        $this->storeId = $storeId;
        $this->status = $status;
        $this->error = $error;
        $this->metadata = $metadata;
        $this->mode = $mode;
        $this->createdOn = $createdOn;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('status', true, FormatterUtils::getTypedEnum(CancelStatus::class))
            ->upsert('mode', true, FormatterUtils::getTypedEnum(AppTokenMode::class))
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'));
    }

    /**
     * Typed-first hydration entry point for `Support\TypedHydrator` -- see `Charge::
     * hydrateFromTyped()`'s doc for the general shape. `error`/`metadata` are read from $body (this
     * response's raw decoded body), not the typed `PaymentError`/`GenericMetadata` models -- see
     * that same doc note. Every other field is a clean 1:1 match against the generated SDK's
     * `UnivaPay\Models\Cancel` -- no spec gap.
     *
     * @param mixed $typed
     * @param array $body
     * @param \Univapay\Compat\Support\CompatContext|null $context
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body, $context)
    {
        if (!($typed instanceof GeneratedCancel)) {
            return null;
        }
        if ($typed->getStatus() === null || $typed->getMode() === null || $typed->getCreatedOn() === null) {
            return null;
        }

        return new self(
            $typed->getId(),
            $typed->getChargeId(),
            $typed->getStoreId(),
            CancelStatus::fromValue($typed->getStatus()),
            array_key_exists('error', $body) ? $body['error'] : null,
            array_key_exists('metadata', $body) ? $body['metadata'] : null,
            AppTokenMode::fromValue($typed->getMode()),
            $typed->getCreatedOn(),
            $context
        );
    }

    protected function pollableStatuses()
    {
        return [(string) CancelStatus::PENDING() => array_diff(CancelStatus::findValues(), [CancelStatus::PENDING()])];
    }

    protected function nativeFetchEquivalent(): string
    {
        return 'CancelsApi::getCancel()';
    }

    protected function nativeUpdateEquivalent(): string
    {
        return 'CancelsApi::updateCancel()';
    }

    protected function nativePollEquivalent(): string
    {
        return 'CancelsApi::pollCancel()';
    }

    protected function fetchCall()
    {
        $bridge = $this->context->bridge();
        $cancels = $bridge->cancels();
        return $bridge->caller()->callTyped(
            function () use ($cancels) {
                return $cancels->getCancel($this->storeId, $this->chargeId, $this->id);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/charges/{$this->chargeId}/cancels/{$this->id}"
        );
    }

    protected function updateCall($updates)
    {
        $request = RequestModelFactory::cancelUpdate($updates);
        $bridge = $this->context->bridge();
        $cancels = $bridge->cancels();
        return $bridge->caller()->callTyped(
            function ($idempotencyKey) use ($cancels, $request) {
                return $cancels->updateCancel($this->storeId, $this->chargeId, $this->id, $request, $idempotencyKey);
            },
            $bridge->handlers(),
            "PATCH /stores/{$this->storeId}/charges/{$this->chargeId}/cancels/{$this->id}"
        );
    }

    /**
     * @return static
     */
    protected function fetchWithPolling()
    {
        $bridge = $this->context->bridge();
        $cancels = $bridge->cancels();
        // NB: CancelsApi::getCancel()'s own generated $polling default is `false` (unlike every
        // other resource's `null`) -- an upstream gotcha. Passed explicitly as literal `true` here
        // regardless, so this call's actual wire behavior does not depend on that default at all.
        $result = $bridge->caller()->callTyped(
            function () use ($cancels) {
                return $cancels->getCancel($this->storeId, $this->chargeId, $this->id, true);
            },
            $bridge->handlers(),
            "GET /stores/{$this->storeId}/charges/{$this->chargeId}/cancels/{$this->id}?polling=true"
        );
        return $this->resolveHydration($result);
    }
}
