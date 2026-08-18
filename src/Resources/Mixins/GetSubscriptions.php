<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Mixins;

use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Enums\SubscriptionStatus;
use Univapay\Compat\Resources\Paginated;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\OptionsValidator;

/**
 * Port of the old SDK's `Resources\Mixins\GetSubscriptions`. See `GetCharges`'s class doc for why
 * the old abstract `getSubscriptionContext()` hook is replaced by a per-endpoint
 * `listSubscriptionsPage()` hook instead: the concrete class (client for merchant-wide listing,
 * `Store` for store-scoped) picks `Support\ListDispatcher::listAllSubscriptions()` vs
 * `listStoreSubscriptions()` itself.
 */
trait GetSubscriptions
{
    use OptionsValidator;

    /**
     * @param array $query
     * @return Paginated
     */
    abstract protected function listSubscriptionsPage(array $query);

    /**
     * @param string|null $search
     * @param SubscriptionStatus|null $status
     * @param AppTokenMode|null $mode
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return Paginated
     */
    public function listSubscriptions(
        $search = null,
        ?SubscriptionStatus $status = null,
        ?AppTokenMode $mode = null,
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        $query = FunctionalUtils::stripNulls([
            'search' => $search,
            'status' => isset($status) ? $status->getValue() : null,
            'mode' => isset($mode) ? $mode->getValue() : null,
            'cursor' => $cursor,
            'limit' => $limit,
            'cursor_direction' => isset($cursorDirection) ? $cursorDirection->getValue() : null,
        ]);

        return $this->listSubscriptionsPage($query);
    }

    /**
     * @param array $opts See listSubscriptions() parameters for valid opts keys.
     * @return Paginated
     */
    public function listSubscriptionsByOptions(array $opts = [])
    {
        $rules = [
            'status' => 'ValidationHelper::getEnumValue',
            'type' => 'ValidationHelper::getEnumValue',
            'cursor_direction' => 'ValidationHelper::getEnumValue',
        ];

        $query = $this->validate(FunctionalUtils::stripNulls($opts), $rules);
        return $this->listSubscriptionsPage($query);
    }
}
