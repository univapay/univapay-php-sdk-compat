<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Mixins;

use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Resources\Paginated;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\OptionsValidator;

/**
 * Port of the old SDK's `Resources\Mixins\GetCancels` (attached to `Charge`). See
 * `GetCharges`'s class doc for why the old abstract `getCancelContext()` hook is replaced by a
 * `listCancelsPage()` hook the owning `Charge` implements directly against its own store id +
 * charge id.
 */
trait GetCancels
{
    use OptionsValidator;

    /**
     * @param array $query
     * @return Paginated
     */
    abstract protected function listCancelsPage(array $query);

    /**
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return Paginated
     */
    public function listCancels(
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        $query = FunctionalUtils::stripNulls([
            'cursor' => $cursor,
            'limit' => $limit,
            'cursor_direction' => isset($cursorDirection) ? $cursorDirection->getValue() : null,
        ]);
        return $this->listCancelsPage($query);
    }

    /**
     * @param array $opts See listCancels() parameters for valid opts keys.
     * @return Paginated
     */
    public function listCancelsByOptions(array $opts = [])
    {
        $rules = [
            'cursor_direction' => 'ValidationHelper::getEnumValue',
        ];

        $query = $this->validate(FunctionalUtils::stripNulls($opts), $rules);
        return $this->listCancelsPage($query);
    }
}
