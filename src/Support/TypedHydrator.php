<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

use Throwable;

/**
 * @internal
 *
 * The typed-first / raw-fallback dispatch point every hydration call site (`Resource::fetch()`/
 * `update()`/`callAndHydrate()`, and the handful of ad hoc call+parse sites that build a `Charge`/
 * `Refund`/`Cancel`/`PaymentToken\*` directly) funnels through.
 *
 * `$targetClass` "flips" to typed-primary simply by declaring a public static
 * `hydrateFromTyped($typed, array $rawBody, $context)` method: given the generated SDK's own
 * deserialized result and this same response's raw decoded body (for the rare field a typed model
 * doesn't carry -- see e.g. `Resources\Charge::hydrateFromTyped()`'s `three_ds`/`error`/`metadata`
 * handling), it returns a fully hydrated instance, or null to decline (falls back to raw). A class
 * with no such method is untouched -- this resolves to the exact `getSchema()->parse($rawBody,
 * [$context])` call every resource used before typed-first hydration existed, so adding
 * `callTyped()`/`resolve()` to a resource's transport wiring is behavior-neutral until that
 * resource also gains `hydrateFromTyped()`.
 */
final class TypedHydrator
{
    /**
     * @param string $targetClass FQCN of a class using the `Jsonable` trait.
     * @param TypedResult $result
     * @param mixed $context Passed as `getSchema()->parse()`'s context arg, and as
     *        `hydrateFromTyped()`'s third argument.
     * @return mixed A hydrated $targetClass instance.
     */
    public static function resolve(string $targetClass, TypedResult $result, $context)
    {
        if ($result->typed !== null && method_exists($targetClass, 'hydrateFromTyped')) {
            try {
                $rawBody = is_array($result->rawBody) ? $result->rawBody : [];
                $hydrated = call_user_func([$targetClass, 'hydrateFromTyped'], $result->typed, $rawBody, $context);
                if ($hydrated !== null) {
                    return $hydrated;
                }
                FallbackRegistry::record($targetClass, FallbackRegistry::REASON_HYDRATION_DECLINED);
            } catch (Throwable $e) {
                FallbackRegistry::record($targetClass, FallbackRegistry::REASON_HYDRATION_EXCEPTION, $e->getMessage());
            }
        } elseif ($result->mapperFailed && method_exists($targetClass, 'hydrateFromTyped')) {
            // Only a resource actually opted into typed hydration cares that the mapper failed --
            // recording this for every un-flipped resource's normal raw operation would be noise.
            FallbackRegistry::record($targetClass, FallbackRegistry::REASON_JSONMAPPER_EXCEPTION);
        }
        return $targetClass::getSchema()->parse($result->rawBody, [$context]);
    }
}
