<?php

namespace Univapay\Compat\Resources\Authentication;

use Exception;

/**
 * Verbatim port (namespace lines only) of the old SDK's `Resources\Authentication\AppJWT`.
 *
 * Decodes the payload segment of the app token JWT (no signature verification -- same as
 * upstream) and dispatches to `StoreAppJWT` or `MerchantAppJWT` depending on whether a
 * `store_id` claim is present.
 */
abstract class AppJWT
{
    public $token;
    public $secret;

    protected function __construct($token, $secret)
    {
        $this->token = $token;
        $this->secret = $secret;
    }

    public static function createToken($appToken, $appSecret)
    {
        try {
            $tokenBody = base64_decode(explode('.', $appToken)[1]);
        } catch (Exception $e) {
            throw new InvalidJWTFormat($appToken);
        }
        $appTokenBody = json_decode($tokenBody, true);

        if ($appTokenBody == null) {
            throw new InvalidJWTFormat('JWT body is not JSON');
        }

        if (array_key_exists('store_id', $appTokenBody)) {
            $class = StoreAppJWT::class;
        } else {
            $class = MerchantAppJWT::class;
        }
        $result = $class::getSchema()->parse($appTokenBody, [$appToken, $appSecret]);
        return $result;
    }
}
