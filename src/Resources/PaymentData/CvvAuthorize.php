<?php

namespace Univapay\Compat\Resources\PaymentData;

use JsonSerializable;
use Money\Currency;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Enums\CvvAuthorizationStatus;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentData\CvvAuthorize`.
 * Property order (enabled, currency, status, chargeId, credentialsId) already matches the
 * constructor.
 */
class CvvAuthorize implements JsonSerializable
{
    use Jsonable;

    public $enabled;
    public $currency;
    public $status;
    public $chargeId;
    public $credentialsId;

    public function __construct(
        $enabled,
        ?Currency $currency = null,
        ?CvvAuthorizationStatus $status = null,
        $chargeId = null,
        $credentialsId = null
    ) {
        $this->enabled = $enabled;
        $this->currency = $currency;
        $this->status = $status;
        $this->chargeId = $chargeId;
        $this->credentialsId = $credentialsId;
    }

    public function jsonSerialize(): array
    {
        return FunctionalUtils::stripNulls([
            'enabled' => $this->enabled,
            'currency' => isset($this->currency) ? $this->currency->jsonSerialize() : null
        ]);
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('currency', false, FormatterUtils::of('getCurrency'))
            ->upsert('status', false, FormatterUtils::getTypedEnum(CvvAuthorizationStatus::class));
    }
}
