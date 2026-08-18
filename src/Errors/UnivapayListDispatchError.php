<?php

namespace Univapay\Compat\Errors;

/**
 * @internal
 *
 * Thrown by `Support\ListDispatcher` (never part of the old-SDK-mirrored public surface itself --
 * the OLD SDK's list mixins silently forwarded unknown option keys straight to the server, or, in
 * three documented cases, silently used the wrong receiver/parser entirely). This layer refuses to
 * guess:
 *
 * - An option key the new transport engine's generated controller has no query parameter for
 *   at all (never will -- e.g. `card_number` on charge listing) is a permanent gap: dropping it
 *   silently would mean "filter ignored, unfiltered results returned", which is worse than a loud
 *   failure.
 * - An option key that maps to a real generated parameter, but the endpoint that parameter lives
 *   on hasn't been wired up yet fails the same way, with a message pointing at the pending
 *   extension instead of a permanent gap.
 * - A key this dispatcher has simply never heard of for a given endpoint is the generic
 *   catch-all case.
 *
 * `UnivapaySDKError`'s own constructor only accepts a `Reason` enum instance (a closed, static
 * set with fixed messages) -- there is no single `Reason` case that fits an arbitrary
 * "unknown option '{$key}' on {$endpoint}" message, so (like `UnivapayUnsupportedFeatureError`)
 * this class bypasses it and calls the grandparent `UnivapayError::__construct()` directly with a
 * fully dynamic message. It is still a `UnivapaySDKError` (`instanceof` holds).
 */
class UnivapayListDispatchError extends UnivapaySDKError
{
    /**
     * @param string $message
     */
    public function __construct($message)
    {
        // Deliberately bypasses UnivapaySDKError::__construct(), which requires a Reason enum
        // instance -- see class doc. Calling the grandparent constructor directly is standard
        // PHP for skipping exactly one level of an inheritance chain (same pattern as
        // UnivapayUnsupportedFeatureError).
        UnivapayError::__construct($message);
    }

    /**
     * @param string $key
     * @param string $endpoint
     * @return self
     */
    public static function unmappableKey($key, $endpoint)
    {
        return new self(
            "Unknown or unsupported list option '$key' for '$endpoint'. The old SDK forwarded " .
            'unrecognized keys straight to the server; this compat layer refuses to do that ' .
            'silently (it would mean the filter is silently ignored). If this key is expected ' .
            'to work, it may be a spec coverage gap -- see the compat package README.'
        );
    }

    /**
     * @param string $key
     * @param string $endpoint
     * @param string $specTask
     * @return self
     */
    public static function pendingSpecExtension($key, $endpoint, $specTask)
    {
        return new self(
            "The '$key' option on '$endpoint' cannot be honored yet: it depends on spec " .
            "extension $specTask, which has not shipped a generated controller method for this " .
            'endpoint yet. TODO: flip this endpoint over once ' . $specTask . ' lands (tracked ' .
            'in the compat package\'s spec backlog).'
        );
    }

    /**
     * @param string $endpoint
     * @param string $specTask
     * @return self
     */
    public static function pendingSpecExtensionEndpoint($endpoint, $specTask)
    {
        return new self(
            "'$endpoint' cannot be honored yet: it depends on spec extension $specTask, which " .
            'has not shipped a generated controller for this endpoint yet. TODO: flip this ' .
            "endpoint over once $specTask lands (tracked in the compat package's spec backlog)."
        );
    }
}
