<?php

namespace Univapay\Compat\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\InstallmentPlanType;
use Univapay\Compat\Enums\SubscriptionPlanType;

/**
 * The backend's subscription plan_type set is revolving | fixed_cycles | fixed_cycle_amount for
 * both installment and subscription plans. Locks the cases the old SDK's lookups lacked
 * (hydrating them fataled with OutOfRangeException).
 */
class PlanTypeBackendValuesTest extends TestCase
{
    public function testInstallmentPlanTypeCoversFullBackendSet()
    {
        $this->assertSame(InstallmentPlanType::REVOLVING(), InstallmentPlanType::fromValue('revolving'));
        $this->assertSame(InstallmentPlanType::FIXED_CYCLES(), InstallmentPlanType::fromValue('fixed_cycles'));
        $this->assertSame(
            InstallmentPlanType::FIXED_CYCLE_AMOUNT(),
            InstallmentPlanType::fromValue('fixed_cycle_amount')
        );
    }

    public function testSubscriptionPlanTypeCoversFullBackendSet()
    {
        $this->assertSame(SubscriptionPlanType::REVOLVING(), SubscriptionPlanType::fromValue('revolving'));
        $this->assertSame(SubscriptionPlanType::FIXED_CYCLES(), SubscriptionPlanType::fromValue('fixed_cycles'));
        $this->assertSame(
            SubscriptionPlanType::FIXED_CYCLE_AMOUNT(),
            SubscriptionPlanType::fromValue('fixed_cycle_amount')
        );
    }
}
