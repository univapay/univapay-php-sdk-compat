<?php

namespace Univapay\Compat\Resources\PaymentMethod;

use JsonSerializable;
use Univapay\Compat\Enums\Field;
use Univapay\Compat\Enums\PaymentType;
use Univapay\Compat\Enums\Reason;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Enums\UsageLimit;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\PaymentData\PaidyData;
use Univapay\Compat\Resources\PaymentData\PhoneNumber;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentMethod\PaidyPayment`,
 * including its client-side guards.
 *
 * `Support\RequestModelFactory::tokenCreate()` routes this class through
 * `RequestModelFactory::buildPaidyData()`, which builds a real `TokenCreatePaidyData`. See
 * `tests/Integration/TokenTest.php` for a live round-trip.
 */
class PaidyPayment extends PaymentMethod implements JsonSerializable
{
    private $paidyData;

    public function __construct(
        PaidyData $paidyData,
        $email = null,
        ?TokenType $type = null,
        ?UsageLimit $usageLimit = null,
        ?array $metadata = null,
        $ipAddress = null
    ) {
        if (isset($paidyData->phoneNumber) && $paidyData->phoneNumber->countryCode != PhoneNumber::JP) {
            throw new UnivapayValidationError(
                Field::PHONE_NUMBER(),
                Reason::ONLY_JAPANESE_PHONE_NUMBER_ALLOWED()
            );
        }

        if (isset($paidyData->shippingAddress) && is_null($paidyData->shippingAddress->zip)) {
            throw new UnivapayValidationError(
                Field::ZIP(),
                Reason::REQUIRED_VALUE()
            );
        }

        parent::__construct(PaymentType::PAIDY(), $type, $email, $ipAddress, $usageLimit, $metadata);
        $this->paidyData = $paidyData;
    }

    // Accepts all types
    protected function acceptsTokenType(?TokenType $tokenType = null)
    {
    }

    public function jsonSerialize(): array
    {
        $parentData = parent::jsonSerialize();
        $parentData['data'] = $this->paidyData->jsonSerialize();

        return $parentData;
    }
}
