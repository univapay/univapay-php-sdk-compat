<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Mixins;

use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;

/**
 * Port of the old SDK's `Resources\Mixins\GetLedgers` (old `Transfer` ledgers -- NOT the same
 * thing as the new SDK's `ChargesApi::listBankTransferLedgers()`, which is a per-charge bank
 * transfer reconciliation endpoint with no old-SDK equivalent at all; see
 * `Support\ListDispatcher::listBankTransferLedgers()`). UNSUPPORTED: the new transport engine has
 * no Transfers/Transfer Ledgers API. See `GetTransfers`'s class doc for why this throws
 * unconditionally rather than dispatching anywhere.
 */
trait GetLedgers
{
    /**
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return never
     * @throws UnivapayUnsupportedFeatureError
     */
    public function listLedgers(
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        throw new UnivapayUnsupportedFeatureError('Transfer ledgers');
    }

    /**
     * @param array $opts
     * @return never
     * @throws UnivapayUnsupportedFeatureError
     */
    public function listLedgersByOptions(array $opts = [])
    {
        throw new UnivapayUnsupportedFeatureError('Transfer ledgers');
    }
}
