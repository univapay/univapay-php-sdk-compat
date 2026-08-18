<?php

namespace Univapay\Compat\Enums;

final class CvvAuthorizationStatus extends TypedEnum
{
    // phpcs:disable
    public static function PENDING() { return self::create(); }
    public static function CURRENT() { return self::create(); }
    public static function FAILED() { return self::create(); }
    public static function INACTIVE() { return self::create(); }
    // The backend's cvv_authorize.status enum also has 'error'; the old SDK's lookup lacked it,
    // which fataled with OutOfRangeException when hydrating a token with that status.
    public static function ERROR() { return self::create(); }
}
