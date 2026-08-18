<?php

namespace Univapay\Compat\Resources\PaymentToken;

use Univapay\Compat\Enums\CallMethod;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\PaymentToken\OnlineToken`.
 * Response-side hydration only (returned by `Charge::onlineToken()`; this data class itself has no
 * HTTP-touching behavior). Property order (issuerToken, callMethod) already matches the
 * constructor.
 *
 * Deviation from the old SDK (found via the example round-trip harness, tools/example-roundtrip,
 * against `IssuerTokenBankTransferExample` in the spec): `GET
 * /stores/{storeId}/charges/{id}/issuer_token` (the endpoint this class hydrates, via
 * `Charge::onlineToken()`/`getChargeIssuerToken()`) is polymorphic across
 * payment types -- the online/d-barai variants return `{issuer_token, call_method, payload,
 * payment_type}`, but the bank_transfer variant returns an entirely different shape (`account_id`,
 * `branch_code`, `branch_name`, `account_holder_name`, `account_number`, `payment_type`) with NO
 * `call_method` field at all. The old schema marked `call_method` required=true (the constructor
 * already type-hinted it nullable, `?CallMethod $callMethod = null`, so only the schema needed to
 * change), which fataled hydrating the bank-transfer variant. Relaxed to optional, same category
 * as `CardData`'s billing/threeDS relaxation. Note this class still only ever hydrates
 * `issuer_token`/`call_method` -- the bank-transfer-specific fields
 * (account_id/branch_code/branch_name/account_holder_name/account_number) are NOT modeled here and
 * are silently dropped for that variant.
 */
class OnlineToken
{
    use Jsonable;

    public $issuerToken;
    public $callMethod;

    /**
     * @param mixed $issuerToken
     */
    public function __construct(
        $issuerToken = null,
        ?CallMethod $callMethod = null
    ) {
        $this->issuerToken = $issuerToken;
        $this->callMethod = $callMethod;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('call_method', false, FormatterUtils::getTypedEnum(CallMethod::class));
    }
}
