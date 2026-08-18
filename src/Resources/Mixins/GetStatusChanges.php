<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Mixins;

use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;

/**
 * Port of the old SDK's `Resources\Mixins\GetStatusChanges` (`Transfer` status changes).
 * UNSUPPORTED: the new transport engine has no Transfers/TransferStatusChanges API. See
 * `GetTransfers`'s class doc for why this throws unconditionally rather than dispatching
 * anywhere.
 */
trait GetStatusChanges
{
    /**
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return never
     * @throws UnivapayUnsupportedFeatureError
     */
    public function listStatusChanges(
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        throw new UnivapayUnsupportedFeatureError('Transfer status changes');
    }

    /**
     * @param array $opts
     * @return never
     * @throws UnivapayUnsupportedFeatureError
     */
    public function listStatusChangesByOptions(array $opts = [])
    {
        throw new UnivapayUnsupportedFeatureError('Transfer status changes');
    }
}
