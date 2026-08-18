<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Mixins;

use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Resources\Paginated;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\OptionsValidator;

/**
 * Port of the old SDK's `Resources\Mixins\GetScheduledPayments` (attached to `Subscription`).
 * Mapping decision (see `Support\ListDispatcher::listSubscriptionPayments()`'s
 * class doc): the generated `SubscriptionsApi::listSubscriptionPayments(storeId,
 * subscriptionId, ...)` has the exact same three query parameters (`limit`/`cursor`/
 * `cursorDirection`, no filters) as old `ScheduledPayment::listScheduledPayments()`, so this is a
 * direct 1:1 mapping, not a lossy one.
 */
trait GetScheduledPayments
{
    use OptionsValidator;

    /**
     * @param array $query
     * @return Paginated
     */
    abstract protected function listScheduledPaymentsPage(array $query);

    /**
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return Paginated
     */
    public function listScheduledPayments(
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        $query = FunctionalUtils::stripNulls([
            'cursor' => $cursor,
            'limit' => $limit,
            'cursor_direction' => isset($cursorDirection) ? $cursorDirection->getValue() : null,
        ]);

        return $this->listScheduledPaymentsPage($query);
    }

    /**
     * @param array $opts See listScheduledPayments() parameters for valid opts keys.
     * @return Paginated
     */
    public function listScheduledPaymentsByOptions(array $opts = [])
    {
        $rules = [
            'cursor_direction' => 'ValidationHelper::getEnumValue',
        ];

        $query = $this->validate(FunctionalUtils::stripNulls($opts), $rules);
        return $this->listScheduledPaymentsPage($query);
    }
}
