<?php

namespace Univapay\Compat\Errors;

class UnivapayRequestError extends UnivapayError
{
    public $url;
    public $status;
    public $code;
    public $errors;

    public function __construct($url, $status, $code, $errors, $httpStatus = 400)
    {
        $this->url = $url;
        $this->status = $status;
        $this->code = $code;
        $this->errors = $errors;
        parent::__construct(print_r([
            'url' => $url,
            'http_status' => $httpStatus,
            'status' => $status,
            'code' => $code,
            'errors' => $errors
        ], true));
    }

    /**
     * @param mixed $url
     * @param mixed $json
     *
     * Value-neutral guard -- see tests/Unit/Architecture/RawJsonConfinementTest's class
     * doc and tests/Hostile/MalformedErrorBodyTest: the old SDK's own `fromJson()` read
     * `$json['status']`/`['code']`/`['errors']` unguarded too (verified byte-for-byte against
     * scratchpad/univapay-php-sdk) -- a malformed 400 body missing one of those keys produced
     * `null` there exactly as it does here, just via a PHP engine warning ("Undefined array
     * key") along the way instead of a clean `??`. Since the RESULTING value is identical either
     * way (`null`), this `??` fallback is not a behavior change, only the removal of a warning
     * that a strict error-to-exception handler (PHPUnit's own default among them) would
     * otherwise turn into a spurious failure.
     */
    public static function fromJson($url, $json)
    {
        return new UnivapayRequestError(
            $url,
            $json['status'] ?? null,
            $json['code'] ?? null,
            $json['errors'] ?? null
        );
    }
}
