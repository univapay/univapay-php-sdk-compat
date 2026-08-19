<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Mixins;

use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Support\DeprecationNotifier;

/**
 * Port of the old SDK's `Resources\Mixins\GetTransfers`. UNSUPPORTED: the new transport engine
 * has no Transfers/Ledgers/ExchangeRates API at all. Both methods keep their old-SDK signatures
 * for call-site compatibility (so a migrated call still type-checks and the Rector migration
 * ruleset's `FlagUnsupportedFeatureRector` can statically flag the call site) but throw
 * `UnivapayUnsupportedFeatureError` unconditionally instead of building a query or dispatching
 * anywhere -- there is no dispatcher endpoint to build one FOR.
 */
trait GetTransfers
{
    /**
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return never
     * @throws UnivapayUnsupportedFeatureError
     */
    public function listTransfers(
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        $deprecationNotice = DeprecationNotifier::notify(
            $this->getBridge()->deprecationNoticesEnabled(),
            static::class . '::listTransfers()',
            "no native equivalent (see the compat README's supported surface matrix)"
        );
        throw new UnivapayUnsupportedFeatureError('Transfer listing');
    }

    /**
     * @param array $opts
     * @return never
     * @throws UnivapayUnsupportedFeatureError
     */
    public function listTransfersByOptions(array $opts = [])
    {
        $deprecationNotice = DeprecationNotifier::notify(
            $this->getBridge()->deprecationNoticesEnabled(),
            static::class . '::listTransfersByOptions()',
            "no native equivalent (see the compat README's supported surface matrix)"
        );
        throw new UnivapayUnsupportedFeatureError('Transfer listing');
    }
}
