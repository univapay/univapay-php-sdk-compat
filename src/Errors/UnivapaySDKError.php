<?php

namespace Univapay\Compat\Errors;

use Univapay\Compat\Enums\Reason;

class UnivapaySDKError extends UnivapayError
{
    public function __construct(Reason $reason)
    {
        parent::__construct($reason->getValue());
    }
}
