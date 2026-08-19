<?php

declare(strict_types=1);

namespace Univapay\Compat\Support;

/**
 * @internal
 *
 * Collects every occasion a typed-first hydration attempt (see `TypedHydrator`) fell back to the
 * raw-body `JsonSchema` path instead of hydrating from `UnivaPay\Models\*`. Purely observational:
 * recording an occurrence never throws, never logs, never touches output -- normal consumers see
 * zero side effects. Exists so tests (differential harness, hostile fixtures) can assert exactly
 * when and why the fallback engaged, and so a future spec gap (a typed model silently missing a
 * field the raw parser reads) surfaces as a loud, specific test assertion instead of quietly
 * changing behavior.
 *
 * Reset between tests via `reset()` -- this is process-wide static state, not scoped to a
 * `CompatContext`/`Bridge` instance.
 */
final class FallbackRegistry
{
    /** A response's typed jsonmapper threw; the caller had no typed model to try at all. */
    public const REASON_JSONMAPPER_EXCEPTION = 'jsonmapper-exception';

    /** `hydrateFromTyped()` existed but returned null, declining to hydrate this payload. */
    public const REASON_HYDRATION_DECLINED = 'typed-hydration-declined';

    /** `hydrateFromTyped()` existed but threw. */
    public const REASON_HYDRATION_EXCEPTION = 'typed-hydration-exception';

    /** @var array<int, array{resource: string, reason: string, detail: string|null}> */
    private static $occurrences = [];

    /** @var callable|null */
    private static $hook;

    /**
     * @param string $resourceClass FQCN of the compat resource that fell back.
     * @param string $reason One of the REASON_* constants.
     * @param string|null $detail Free-form detail (e.g. an exception message).
     */
    public static function record(string $resourceClass, string $reason, ?string $detail = null): void
    {
        $entry = ['resource' => $resourceClass, 'reason' => $reason, 'detail' => $detail];
        self::$occurrences[] = $entry;
        if (self::$hook !== null) {
            call_user_func(self::$hook, $entry);
        }
    }

    /** @return array<int, array{resource: string, reason: string, detail: string|null}> */
    public static function occurrences(): array
    {
        return self::$occurrences;
    }

    public static function reset(): void
    {
        self::$occurrences = [];
    }

    /**
     * Optional callable invoked with the same entry array `record()` stores, in addition to
     * storing it. Pass null to remove. Consumers/tests can use this instead of (or alongside)
     * polling `occurrences()`.
     */
    public static function setHook(?callable $hook): void
    {
        self::$hook = $hook;
    }
}
