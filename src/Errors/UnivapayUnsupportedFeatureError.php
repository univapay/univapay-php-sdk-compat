<?php

namespace Univapay\Compat\Errors;

/**
 * Thrown by compat methods that mirror old-SDK surface with no equivalent in the new
 * transport engine (e.g. Transfers, Transfer Ledgers, TransferStatusChanges, ApplePayPayment
 * token creation, Charge::qrMerchantToken() -- deprecated upstream). The Rector migration
 * ruleset (univapay/univapay-sdk-migrate) statically flags call sites that reach these methods
 * with a `@univapay-migrate:unsupported` comment; this exception is the runtime counterpart for
 * call sites the static flagger could not resolve (dynamic receivers, `(verify)` cases).
 */
class UnivapayUnsupportedFeatureError extends UnivapaySDKError
{
    /**
     * The PHP SDK migration guide page (src/content/guides/php-sdk-migration.md in the docs
     * repo, + its src-ja/ counterpart) is authored with a pinned `slug: php-sdk-migration`,
     * identical in both `toc.yml` files, nested under "Onboarding Guides" (`onboarding-guides`)
     * > "Guides" (`guides`) -- giving the in-portal path `onboarding-guides/guides/php-sdk-migration`
     * below, matching the `#/http/<group>/<group>/<slug>` hash-routing convention every other
     * cross-guide link in that portal uses, and matching
     * `Univapay\Migrate\GuideUrl::MIGRATION_GUIDE` in the sibling migrate package.
     */
    public const GUIDE_URL = 'https://univapay.com/docs/#/http/onboarding-guides/guides/'
        . 'php-sdk-migration#unsupported-features';

    /**
     * @param string $feature Human-readable name of the unsupported feature/method, e.g.
     *                        "Transfer ledgers" or "Charge::qrMerchantToken()".
     */
    public function __construct($feature)
    {
        // Deliberately bypasses UnivapaySDKError::__construct(), which requires a Reason enum
        // instance -- there is no single Reason case that fits an arbitrary feature name, and
        // composing a message would fight the "single class const with TODO" surface this class
        // is meant to have. Calling the grandparent constructor directly is standard PHP for
        // skipping exactly one level of an inheritance chain.
        UnivapayError::__construct(
            "The '$feature' feature is not supported by this compatibility layer. " .
            'See ' . self::GUIDE_URL
        );
    }
}
