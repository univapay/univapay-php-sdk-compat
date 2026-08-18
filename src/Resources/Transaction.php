<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use DateTime;
use Money\Currency;
use Money\Money;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Enums\TransactionType;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\Transaction` (namespace lines only) -- pure data class, exactly
 * as upstream: old `Transaction` never extended `Resource` (no `fetch()`/`update()` -- transaction
 * history items were never individually fetchable by id in either SDK, only listed).
 *
 * SUPPORTED: backed by `GET /transaction_history` and
 * `GET /stores/{storeId}/transaction_history` via `Support\ListDispatcher::listTransactions()`/
 * `listStoreTransactions()`, distinct from `Transfer`'s PERMANENT unsupported status.
 *
 * The old constructor's private `$context` field is preserved for fidelity (it was never read
 * anywhere in the old SDK either) but excluded from the hydration schema automatically: `Utility\
 * FunctionalUtils::getClassVarsAssoc()` only sees PUBLIC properties from its own (unrelated)
 * calling scope, so a private property is invisible to `JsonSchema::fromClass()`'s reflection --
 * the same reason none of the other ported classes need an explicit exclusion for their own
 * private/protected fields.
 */
class Transaction
{
    use Jsonable;

    public $id;
    public $storeId;
    public $resourceId;
    public $chargeId;
    public $currency;
    public $amount;
    public $amountFormatted;
    public $type;
    public $status;
    public $metadata;
    public $mode;
    public $userData;
    public $createdOn;
    private $context;

    /**
     * @param mixed $id
     * @param mixed $storeId
     * @param mixed $resourceId
     * @param mixed $chargeId
     * @param mixed $amountFormatted
     * @param mixed $metadata
     * @param mixed $userData
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $storeId,
        $resourceId,
        $chargeId,
        Currency $currency,
        Money $amount,
        $amountFormatted,
        TransactionType $type,
        ChargeStatus $status,
        $metadata,
        AppTokenMode $mode,
        $userData,
        DateTime $createdOn,
        $context = null
    ) {
        $this->id = $id;
        $this->storeId = $storeId;
        $this->resourceId = $resourceId;
        $this->chargeId = $chargeId;
        $this->currency = $currency;
        $this->amount = $amount;
        $this->amountFormatted = $amountFormatted;
        $this->type = $type;
        $this->status = $status;
        $this->metadata = $metadata;
        $this->mode = $mode;
        $this->userData = $userData;
        $this->createdOn = $createdOn;
        $this->context = $context;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('currency', true, FormatterUtils::of('getCurrency'))
            ->upsert('amount', true, FormatterUtils::getMoney('currency'))
            ->upsert('type', true, FormatterUtils::getTypedEnum(TransactionType::class))
            ->upsert('status', true, FormatterUtils::getTypedEnum(ChargeStatus::class))
            ->upsert('mode', true, FormatterUtils::getTypedEnum(AppTokenMode::class))
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'));
    }
}
