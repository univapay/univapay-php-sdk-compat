<?php

namespace Univapay\Compat\Resources\PaymentMethod;

use JsonSerializable;
use Univapay\Compat\Enums\Field;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\Reason;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Enums\UsageLimit;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\PaymentData\ConvenienceStoreData;
use Univapay\Compat\Resources\PaymentData\PhoneNumber;

/**
 * Verbatim port (namespace line only) of the old SDK's
 * `Resources\PaymentMethod\ConvenienceStorePayment`, including its client-side guards
 * (Japanese-phone-number-only, expiration-period 7-30 day bounds).
 */
class ConvenienceStorePayment extends PaymentMethod implements JsonSerializable
{
    private $convenienceStoreData;

    public function __construct(
        $email,
        ConvenienceStoreData $convenienceStoreData,
        ?TokenType $type = null,
        ?UsageLimit $usageLimit = null,
        ?array $metadata = null,
        $ipAddress = null
    ) {
        if ($convenienceStoreData->phoneNumber->countryCode != PhoneNumber::JP) {
            throw new UnivapayValidationError(
                Field::PHONE_NUMBER(),
                Reason::ONLY_JAPANESE_PHONE_NUMBER_ALLOWED()
            );
        }
        if (
            isset($convenienceStoreData->expirationPeriod) &&
            ($convenienceStoreData->expirationPeriod->d < 7 || $convenienceStoreData->expirationPeriod->d > 30)
        ) {
            throw new UnivapayValidationError(
                Field::EXPIRATION_PERIOD(),
                Reason::EXPIRATION_DATE_OUT_OF_BOUNDS()
            );
        }

        parent::__construct(PaymentType::KONBINI(), $type, $email, $ipAddress, $usageLimit, $metadata);
        $this->convenienceStoreData = $convenienceStoreData;
    }

    // Accepts all types
    protected function acceptsTokenType(?TokenType $tokenType = null)
    {
    }

    public function jsonSerialize(): array
    {
        $parentData = parent::jsonSerialize();
        $parentData['data'] = $this->convenienceStoreData->jsonSerialize();

        return $parentData;
    }
}
