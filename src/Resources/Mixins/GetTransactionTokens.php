<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Mixins;

use Univapay\Compat\Enums\ActiveFilter;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Enums\Field;
use Univapay\Compat\Enums\Reason;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\Paginated;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\OptionsValidator;

/**
 * Port of the old SDK's `Resources\Mixins\GetTransactionTokens`.
 *
 * `listTransactionTokens()` and `listTransactionTokensByOptions()` both dispatch through the same
 * `listTransactionTokensPage()` hook and parse the response as transaction tokens.
 */
trait GetTransactionTokens
{
    use OptionsValidator;

    /**
     * @param array $query
     * @return Paginated
     */
    abstract protected function listTransactionTokensPage(array $query);

    /**
     * @param string|null $search
     * @param string|null $univapayCustomerId
     * @param TokenType|null $type
     * @param AppTokenMode|null $mode
     * @param ActiveFilter|null $active
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return Paginated
     */
    public function listTransactionTokens(
        $search = null,
        $univapayCustomerId = null,
        ?TokenType $type = null,
        ?AppTokenMode $mode = null,
        ?ActiveFilter $active = null,
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        if (isset($type) && $type === TokenType::ONE_TIME()) {
            throw new UnivapayValidationError(Field::TYPE(), Reason::INVALID_TOKEN_TYPE());
        }

        $query = FunctionalUtils::stripNulls([
            'search' => $search,
            'active' => isset($active) ? $active->getValue() : null,
            'customer_id' => $univapayCustomerId,
            'type' => isset($type) ? $type->getValue() : null,
            'mode' => isset($mode) ? $mode->getValue() : null,
            'cursor' => $cursor,
            'limit' => $limit,
            'cursor_direction' => isset($cursorDirection) ? $cursorDirection->getValue() : null,
        ]);
        return $this->listTransactionTokensPage($query);
    }

    /**
     * @param array $opts See listTransactionTokens() parameters for valid opts keys.
     * @return Paginated
     */
    public function listTransactionTokensByOptions(array $opts = [])
    {
        $rules = [
            'active' => 'ValidationHelper::getEnumValue',
            'status' => 'ValidationHelper::getEnumValue',
            'type' => 'ValidationHelper::getEnumValue',
            'mode' => 'ValidationHelper::getEnumValue',
            'cursor_direction' => 'ValidationHelper::getEnumValue',
        ];

        $query = $this->validate(FunctionalUtils::stripNulls($opts), $rules);
        // Same hook listTransactionTokens() above calls.
        return $this->listTransactionTokensPage($query);
    }
}
