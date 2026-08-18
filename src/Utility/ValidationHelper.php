<?php

namespace Univapay\Compat\Utility;

use DateTime;
use Univapay\Compat\Enums\TypedEnum;

/**
 * @internal
 */
class ValidationHelper
{
    public static function isArray(array $array)
    {
        return $array;
    }

    public static function getAtomDate(DateTime $date)
    {
        return $date->format(DateTime::ATOM);
    }

    public static function getEnumValue(TypedEnum $enum)
    {
        return $enum->getValue();
    }
}
