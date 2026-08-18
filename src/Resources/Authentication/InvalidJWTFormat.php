<?php

namespace Univapay\Compat\Resources\Authentication;

use Exception;
use Throwable;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\Authentication\InvalidJWTFormat`.
 */
class InvalidJWTFormat extends Exception
{
    public function __construct($msg, $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("Unparsable JWT: $msg", $code, $previous);
    }
}
