<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use DateTime;
use Money\Currency;
use Money\Money;
use Univapay\Compat\Enums\LedgerOrigin;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\Ledger` (namespace lines only) -- pure data class, exactly as
 * upstream: unlike `Transfer`/`TransferStatusChange`, old `Ledger` never extended `Resource` at
 * all (no `$context`, no `fetch()`/`update()`) -- it only ever appeared as an ALREADY-HYDRATED item
 * inside a `Transfer::listLedgers()` page, never fetched standalone. UNSUPPORTED for the same
 * permanent reason as `Transfer` (see its class doc), but there is no HTTP-touching surface on this
 * class itself to throw from -- the throw lives entirely on `Mixins\GetLedgers` and
 * `Transfer::fetchCall()`/`updateCall()`.
 */
class Ledger
{
    use Jsonable;

    public $id;
    public $storeId;
    public $currency;
    public $amount;
    public $amountFormatted;
    public $percentFee;
    public $flatFeeCurrency;
    public $flatFeeAmount;
    public $flatFeeFormatted;
    public $exchangeRate;
    public $origin;
    public $note;
    public $createdOn;

    public function __construct(
        $id,
        $storeId,
        Currency $currency,
        Money $amount,
        $amountFormatted,
        $percentFee,
        Currency $flatFeeCurrency,
        Money $flatFeeAmount,
        $flatFeeFormatted,
        $exchangeRate,
        LedgerOrigin $origin,
        $note,
        DateTime $createdOn
    ) {
        $this->id = $id;
        $this->storeId = $storeId;
        $this->currency = $currency;
        $this->amount = $amount;
        $this->amountFormatted = $amountFormatted;
        $this->percentFee = $percentFee;
        $this->flatFeeCurrency = $flatFeeCurrency;
        $this->flatFeeAmount = $flatFeeAmount;
        $this->flatFeeFormatted = $flatFeeFormatted;
        $this->exchangeRate = $exchangeRate;
        $this->origin = $origin;
        $this->note = $note;
        $this->createdOn = $createdOn;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('currency', true, FormatterUtils::of('getCurrency'))
            ->upsert('amount', true, FormatterUtils::getMoney('currency'))
            ->upsert('flat_fee_currency', true, FormatterUtils::of('getCurrency'))
            ->upsert('flat_fee_amount', true, FormatterUtils::getMoney('flat_fee_currency'))
            ->upsert('origin', true, FormatterUtils::getTypedEnum(LedgerOrigin::class))
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'));
    }
}
