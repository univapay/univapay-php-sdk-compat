<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use DateTime;
use Money\Currency;
use Money\Money;
use Univapay\Compat\Enums\TransferStatus;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\Mixins\GetLedgers;
use Univapay\Compat\Resources\Mixins\GetStatusChanges;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\Transfer` (namespace lines + transport plumbing only -- public
 * props are otherwise verbatim). Property order (bankAccountId .. createdOn -- `id` comes first
 * for free via the inherited `Resource::$id`) already matches the old constructor.
 *
 * UNSUPPORTED, PERMANENTLY: the new transport engine has no Transfers API at all (no
 * `Apis\TransfersApi`, no `listTransfers`/`listLedgers`/`listStatusChanges` generated controller
 * methods anywhere).
 *
 * Still a FULL, hydration-capable data class rather than a bare stub: webhook transfer events
 * (`TRANSFER_CREATED`/`TRANSFER_UPDATED`/`TRANSFER_FINALIZED`, via
 * `UnivapayClient::parseWebhookData()`) still hydrate a real `Transfer` instance from the payload
 * the server pushes over the webhook channel -- that data keeps arriving regardless of whether the
 * REST API to fetch/list it exists in THIS transport engine. Every HTTP-touching method
 * (`fetch()`/`update()` inherited from `Resource`, `listLedgers()`/`listStatusChanges()` via the
 * mixins below) throws `UnivapayUnsupportedFeatureError` unconditionally.
 *
 * `GetLedgers`/`GetStatusChanges` (compat's rewritten, throw-only versions) no longer `use`
 * `OptionsValidator` at all and share no method names with each other, so -- unlike old
 * `Transfer`'s `use GetLedgers, GetStatusChanges { GetLedgers::validate insteadof
 * GetStatusChanges; }` -- combining them here needs no conflict-resolution block.
 */
class Transfer extends Resource
{
    use Jsonable;
    use GetLedgers;
    use GetStatusChanges;

    public $bankAccountId;
    public $currency;
    public $amount;
    public $amountFormatted;
    public $status;
    public $errorCode;
    public $errorText;
    public $metadata;
    public $note;
    public $from;
    public $to;
    public $createdOn;

    /**
     * @param mixed $id
     * @param mixed $bankAccountId
     * @param mixed $amountFormatted
     * @param mixed $errorCode
     * @param mixed $errorText
     * @param mixed $metadata
     * @param mixed $note
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $bankAccountId,
        Currency $currency,
        Money $amount,
        $amountFormatted,
        TransferStatus $status,
        $errorCode,
        $errorText,
        $metadata,
        $note,
        DateTime $from,
        DateTime $to,
        DateTime $createdOn,
        $context = null
    ) {
        parent::__construct($id, $context);
        $this->bankAccountId = $bankAccountId;
        $this->currency = $currency;
        $this->amount = $amount;
        $this->amountFormatted = $amountFormatted;
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->errorText = $errorText;
        $this->metadata = $metadata;
        $this->note = $note;
        $this->from = $from;
        $this->to = $to;
        $this->createdOn = $createdOn;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('currency', true, FormatterUtils::of('getCurrency'))
            ->upsert('amount', true, FormatterUtils::getMoney('currency'))
            ->upsert('status', true, FormatterUtils::getTypedEnum(TransferStatus::class))
            ->upsert('from', true, FormatterUtils::of('getDateTime'))
            ->upsert('to', true, FormatterUtils::of('getDateTime'))
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'));
    }

    protected function fetchCall()
    {
        throw new UnivapayUnsupportedFeatureError('Transfer::fetch() (Transfers)');
    }

    protected function updateCall($updates)
    {
        throw new UnivapayUnsupportedFeatureError('Transfer::update() (Transfers)');
    }
}
