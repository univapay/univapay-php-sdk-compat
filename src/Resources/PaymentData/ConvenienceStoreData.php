<?php

namespace Univapay\Compat\Resources\PaymentData;

use DateInterval;
use JsonSerializable;
use Univapay\Compat\Enums\ConvenienceStore;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentData\ConvenienceStoreData`.
 * Property order (customerName, phoneNumber, convenienceStore, expirationPeriod) already matches
 * the constructor.
 */
class ConvenienceStoreData implements JsonSerializable
{
    use Jsonable;

    public $customerName;
    public $phoneNumber;
    public $convenienceStore;
    public $expirationPeriod;

    public function __construct(
        $customerName,
        PhoneNumber $phoneNumber,
        ConvenienceStore $convenienceStore,
        ?DateInterval $expirationPeriod = null
    ) {
        $this->customerName = $customerName;
        $this->phoneNumber = $phoneNumber;
        $this->convenienceStore = $convenienceStore;
        $this->expirationPeriod = $expirationPeriod;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('phone_number', true, PhoneNumber::getSchema()->getParser())
            ->upsert('convenience_store', true, FormatterUtils::getTypedEnum(ConvenienceStore::class))
            ->upsert('expiration_period', true, FormatterUtils::of('getDateInterval'));
    }

    public function jsonSerialize(): array
    {
        return FunctionalUtils::stripNulls([
            'customer_name' => $this->customerName,
            'convenience_store' => $this->convenienceStore->getValue(),
            'expiration_period' => isset($this->expirationPeriod)
                ? FormatterUtils::formatDateIntervalISO($this->expirationPeriod)
                : null,
            'phone_number' => $this->phoneNumber->jsonSerialize()
        ]);
    }
}
