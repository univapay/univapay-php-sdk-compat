<?php

namespace Univapay\Compat\Errors;

use Univapay\Compat\Enums\Reason;

class UnivapayLogicError extends UnivapayRequestError
{
    public function __construct(Reason $reason)
    {
        parent::__construct('preflight', 'error', $reason->getValue(), null);
    }
}
