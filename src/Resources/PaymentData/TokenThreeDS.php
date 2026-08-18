<?php

namespace Univapay\Compat\Resources\PaymentData;

use Univapay\Compat\Enums\ThreeDSStatus;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentData\TokenThreeDS`
 * (token-level 3DS enablement, distinct from the charge-level `PaymentThreeDS`/`ThreeDSMPI`
 * pair -- this one has no MPI fields). Property order (enabled, redirectEndpoint, status,
 * redirectId, error) already matches the constructor.
 */
class TokenThreeDS
{
    use Jsonable;

    public $enabled;
    public $redirectEndpoint;
    public $status;
    public $redirectId;
    public $error;

    /**
     * Three DS for Transaction Token
     *
     * @param bool $enabled enable 3DS for this transaction
     * @param int $redirectEndpoint redirect endpoint, where the user will be redirected after 3DS authentication
     */
    public function __construct(
        $enabled,
        $redirectEndpoint,
        ?ThreeDSStatus $status = null,
        $redirectId = null,
        $error = null
    ) {
        $this->enabled = $enabled;
        $this->redirectEndpoint = $redirectEndpoint;
        $this->status = $status;
        $this->redirectId = $redirectId;
        $this->error = $error;
    }

    public function jsonSerialize(): array
    {
        return FunctionalUtils::stripNulls([
            'enabled' => $this->enabled,
            'redirect_endpoint' => $this->redirectEndpoint
        ]);
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('status', false, FormatterUtils::getTypedEnum(ThreeDSStatus::class));
    }
}
