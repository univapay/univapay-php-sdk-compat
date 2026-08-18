<?php

declare(strict_types=1);

namespace Univapay\Compat\Tools\ExampleRoundTrip;

use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * Generic, reflection-driven type spot-check -- no per-class hardcoding needed. Every compat
 * resource's constructor already declares the REAL expected type for each hydrated property
 * (`Money\Money $requestedAmount`, `DateTime $createdOn`, `ChargeStatus $status`, a nested
 * resource class, ...) -- see `Resources\Charge`'s constructor for a representative example. This
 * walks those constructor parameter type hints and, for every parameter whose declared type is a
 * CLASS (not a scalar/array builtin), asserts the parsed instance's same-named property actually
 * `instanceof` that class. This mechanically checks a Money instance where a money pair is
 * present, a DateTime for date-time fields, a TypedEnum for enum fields, plus nested-resource
 * fields (Card, BillingData, ScheduleSettings, ...) for free, because all of those are just
 * "constructor parameter has a class type hint" to this checker.
 */
class SpotChecker
{
    /**
     * @return array<int, array{property: string, expectedType: string, actualType: string, ok: bool}>
     */
    public static function check(string $targetClass, $instance): array
    {
        $findings = [];
        try {
            $constructor = (new ReflectionClass($targetClass))->getConstructor();
        } catch (Throwable $e) {
            return [[
                'property' => '(constructor reflection)',
                'expectedType' => '?',
                'actualType' => get_class($e) . ': ' . $e->getMessage(),
                'ok' => false,
            ]];
        }
        if ($constructor === null) {
            return $findings;
        }

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            // The harness never constructs a real CompatContext (see Harness's class doc) --
            // context is EXPECTED to be null on every parsed instance, that's an artifact of how
            // this harness calls parse(), not a data/parser mismatch worth reporting.
            if ($name === 'context') {
                continue;
            }
            $type = $param->getType();
            if (!($type instanceof ReflectionNamedType) || $type->isBuiltin()) {
                continue;
            }
            $expectedClass = $type->getName();
            if (!property_exists($instance, $name)) {
                continue;
            }
            $value = $instance->{$name};
            if ($value === null) {
                // null is fine when the type is nullable OR the field was legitimately absent
                // (required=false in the schema); either way there's nothing to instanceof-check.
                continue;
            }
            $ok = $value instanceof $expectedClass;
            $findings[] = [
                'property' => $name,
                'expectedType' => $expectedClass,
                'actualType' => is_object($value) ? get_class($value) : gettype($value),
                'ok' => $ok,
            ];
        }
        return $findings;
    }
}
