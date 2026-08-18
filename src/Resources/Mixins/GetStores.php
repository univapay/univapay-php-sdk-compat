<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Mixins;

use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Resources\Paginated;
use Univapay\Compat\Support\DeprecationNotifier;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\OptionsValidator;

/**
 * Port of the old SDK's `Resources\Mixins\GetStores`.
 *
 * `listStores()` and `listStoresByOptions()` both dispatch through the same
 * `listStoresPage()` hook. This trait has exactly ONE abstract hook, so there is no second,
 * differently-named context getter either method could call instead.
 */
trait GetStores
{
    use OptionsValidator;

    /**
     * @param array $query
     * @return Paginated
     */
    abstract protected function listStoresPage(array $query);

    /**
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return Paginated
     */
    public function listStores(
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        $deprecationNotice = DeprecationNotifier::notify(
            $this->getBridge()->deprecationNoticesEnabled(),
            static::class . '::listStores()',
            'StoresApi::listStores()'
        );
        $query = FunctionalUtils::stripNulls([
            'cursor' => $cursor,
            'limit' => $limit,
            'cursor_direction' => isset($cursorDirection) ? $cursorDirection->getValue() : null,
        ]);
        return $this->listStoresPage($query);
    }

    /**
     * @param array $opts See listStores() parameters for valid opts keys.
     * @return Paginated
     */
    public function listStoresByOptions(array $opts = [])
    {
        $deprecationNotice = DeprecationNotifier::notify(
            $this->getBridge()->deprecationNoticesEnabled(),
            static::class . '::listStoresByOptions()',
            'StoresApi::listStores()'
        );
        $rules = [
            'cursor_direction' => 'ValidationHelper::getEnumValue',
        ];

        $query = $this->validate(FunctionalUtils::stripNulls($opts), $rules);
        return $this->listStoresPage($query);
    }
}
