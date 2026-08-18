<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Mixins;

use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;

/**
 * Port of the old SDK's `Resources\Mixins\GetBankAccounts`. UNSUPPORTED: the new transport engine
 * has no Bank Accounts API at all (no `Apis\BankAccountsApi`, no `listBankAccounts` generated
 * controller method anywhere). Same throw-only shape as `GetTransfers` (see its class doc) --
 * both methods keep their old-SDK signatures for call-site compatibility (so a migrated call
 * still type-checks and the Rector migration ruleset's `FlagUnsupportedFeatureRector` can
 * statically flag the call site) but throw `UnivapayUnsupportedFeatureError` unconditionally
 * instead of building a query or dispatching anywhere -- there is no dispatcher endpoint to build
 * one FOR.
 *
 * NOTE: old SDK's second method name here has a typo -- `listBankAccountContextsByOptions()`, not
 * `listBankAccountsByOptions()` (every other mixin's `...ByOptions()` method is named after the
 * resource, not "...Contexts..."). Preserved VERBATIM: this compat layer's whole purpose is
 * reproducing the old SDK's exact public method names so unmodified consumer code keeps working,
 * typos included -- "fixing" it here would silently break any caller still using the old name.
 */
trait GetBankAccounts
{
    /**
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return never
     * @throws UnivapayUnsupportedFeatureError
     */
    public function listBankAccounts(
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        throw new UnivapayUnsupportedFeatureError('Bank account listing');
    }

    /**
     * @param array $opts
     * @return never
     * @throws UnivapayUnsupportedFeatureError
     */
    public function listBankAccountContextsByOptions(array $opts = [])
    {
        throw new UnivapayUnsupportedFeatureError('Bank account listing');
    }
}
