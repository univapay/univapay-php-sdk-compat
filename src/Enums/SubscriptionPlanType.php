<?php

namespace Univapay\Compat\Enums;

final class SubscriptionPlanType extends TypedEnum
{
    // phpcs:disable
    public static function NONE() { return self::create('null'); } // Only when deleting an subscription plan via patch
    public static function FIXED_CYCLES() { return self::create(); }
    public static function FIXED_CYCLE_AMOUNT() { return self::create(); }
    // The backend's plan_type set also has 'revolving'; the old SDK's lookup lacked it, which
    // fataled with OutOfRangeException when hydrating a subscription using that plan type.
    public static function REVOLVING() { return self::create(); }
}
