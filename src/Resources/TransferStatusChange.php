<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use DateTime;
use Univapay\Compat\Enums\TransferStatus;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\TransferStatusChange` (namespace lines + transport plumbing
 * only -- public props are otherwise verbatim; old code's redundant `public $id;` re-declaration
 * -- already provided by the inherited `Resource::$id` -- is dropped, matching this repo's
 * established convention, e.g. `Charge`/`Subscription`/`TransactionToken`). UNSUPPORTED,
 * PERMANENTLY, same rationale as `Transfer` (see its class doc): items of this shape are only ever
 * produced already-hydrated inside a `Transfer::listStatusChanges()` page (itself unconditionally
 * throwing), so this class exists purely so that hydration path -- and any future webhook or
 * fixture exercising it -- type-checks and parses correctly; `fetchCall()`/`updateCall()` (required
 * by the inherited `Resource`) both throw.
 */
class TransferStatusChange extends Resource
{
    use Jsonable;

    public $merchantId;
    public $transferId;
    public $oldStatus;
    public $newStatus;
    public $reason;
    public $createdOn;

    /**
     * @param mixed $id
     * @param mixed $merchantId
     * @param mixed $transferId
     * @param mixed $reason
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $merchantId,
        $transferId,
        TransferStatus $oldStatus,
        TransferStatus $newStatus,
        $reason,
        DateTime $createdOn,
        $context = null
    ) {
        parent::__construct($id, $context);
        $this->merchantId = $merchantId;
        $this->transferId = $transferId;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        $this->reason = $reason;
        $this->createdOn = $createdOn;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('old_status', true, FormatterUtils::getTypedEnum(TransferStatus::class))
            ->upsert('new_status', true, FormatterUtils::getTypedEnum(TransferStatus::class))
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'));
    }

    protected function fetchCall()
    {
        throw new UnivapayUnsupportedFeatureError('TransferStatusChange::fetch() (Transfers)');
    }

    protected function updateCall($updates)
    {
        throw new UnivapayUnsupportedFeatureError('TransferStatusChange::update() (Transfers)');
    }
}
