<?php

namespace Univapay\Compat\Resources\Subscription;

use InvalidArgumentException;
use JsonSerializable;
use Univapay\Compat\Enums\Field;
use Univapay\Compat\Enums\InstallmentPlanType;
use Univapay\Compat\Enums\Reason;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace line only) of the old SDK's
 * `Resources\Subscription\InstallmentPlan`, including its plan-type/argument-combination guards.
 * Property order (planType, fixedCycles) already matches the constructor.
 */
class InstallmentPlan implements JsonSerializable
{
    use Jsonable;

    public $planType;
    public $fixedCycles;

    public function __construct(InstallmentPlanType $planType, $fixedCycles = null)
    {
        switch ($planType) {
            case InstallmentPlanType::NONE():
            case InstallmentPlanType::REVOLVING():
                if ($fixedCycles != null) {
                    throw new InvalidArgumentException(
                        'None or revolving plans do not accept $fixedCycles or $fixedCycleAmount'
                    );
                }
                break;
            case InstallmentPlanType::FIXED_CYCLES():
                if ($fixedCycles == null) {
                    throw new InvalidArgumentException(
                        'Fixed cycle plans requires $fixedCycles and not $fixedCycleAmount'
                    );
                }
                break;
        }
        if (isset($fixedCycles) && $fixedCycles < 2) {
            throw new UnivapayValidationError(Field::FIXED_CYCLES(), Reason::NEED_AT_LEAST_TWO_CYCLES());
        }

        $this->planType = $planType;
        $this->fixedCycles = $fixedCycles;
    }

    public function jsonSerialize(): array
    {
        $data = ['plan_type' => $this->planType->getValue()];
        switch ($this->planType) {
            case InstallmentPlanType::FIXED_CYCLES():
                $data[$this->planType->getValue()] = $this->fixedCycles;
                break;
        }
        return $data;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('plan_type', true, FormatterUtils::getTypedEnum(InstallmentPlanType::class));
    }
}
