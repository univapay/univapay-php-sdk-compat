<?php

namespace Univapay\Compat\Resources\Authentication;

use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\Authentication\MerchantAppJWT`.
 *
 * `JsonSchema::fromClass()` derives this class's schema field order from
 * `Utility\FunctionalUtils::getClassVarsAssoc()`, which sees only this class's PUBLIC properties,
 * in DECLARATION order (with `includeParentVars = false`, as this class calls it) -- it never
 * reorders them relative to the constructor. The schema mechanism therefore requires a class's
 * public property declaration order to exactly match its constructor's parameter order (see the
 * sibling `StoreAppJWT`, whose 9 declared properties match its 9-parameter constructor order).
 *
 * This class declares properties in constructor order (`sub, iat, merchantId, creatorId, version,
 * jti`) and carries no `$issuedAt` field: `Support\Bridge` wires
 * `BearerAuthCredentialsBuilder::init($jwt->secret, $jwt->token)` directly from these properties,
 * so property order must bind `$jwt->token`/`$jwt->secret` to the right values for a
 * merchant-level (non-store-scoped) app token to authenticate correctly. See
 * tests/Unit/Resources/Authentication/AppJWTTest.php.
 */
class MerchantAppJWT extends AppJWT
{
    use Jsonable;

    public $sub;
    public $iat;
    public $merchantId;
    public $creatorId;
    public $version;
    public $jti;

    public function __construct(
        $sub,
        $iat,
        $merchantId,
        $creatorId,
        $version,
        $jti,
        $token,
        $secret
    ) {
        if ($sub != 'app_token') {
            throw new InvalidJWTFormat('Invalid subject');
        }
        parent::__construct($token, $secret);
        $this->iat = $iat;
        $this->merchantId = $merchantId;
        $this->creatorId = $creatorId;
        $this->version = $version;
        $this->jti = $jti;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(MerchantAppJWT::class, true, false);
    }
}
