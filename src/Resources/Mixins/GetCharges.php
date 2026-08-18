<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Mixins;

use DateTime;
use Money\Currency;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Resources\Paginated;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\OptionsValidator;

/**
 * Port of the old SDK's `Resources\Mixins\GetCharges`. Old `listCharges()`/`listChargesByOptions()`
 * built the same snake_case query array shown here and handed it, together with `Charge::class`
 * and an abstract `getChargeContext()` hook, to `Utility\RequesterUtils::executeGetPaginated()`.
 *
 * That hook is replaced here by `listChargesPage()`: rather than returning a context object for a
 * SHARED fetch function to interpret, each concrete class implementing this trait (the client
 * itself for merchant-wide listing, `Store` for store-scoped listing) now owns its OWN translation
 * to `Support\ListDispatcher::listAllCharges()`/`listStoreCharges()` and its own item-parser
 * binding.
 *
 * `$gatewayCredentialsId`/`$gatewayTransactionId` are accepted but -- exactly as in the old
 * SDK -- never added to the query array below. This dead-parameter behavior is preserved here for
 * old-SDK call-site parity (verbatim positional signature), not because it is desirable.
 */
trait GetCharges
{
    use OptionsValidator;

    /**
     * @param array $query
     * @return Paginated
     */
    abstract protected function listChargesPage(array $query);

    /**
     * @param string|null $lastFour
     * @param string|null $name
     * @param string|null $expMonth
     * @param string|null $expYear
     * @param string|null $cardNumber
     * @param DateTime|null $from
     * @param DateTime|null $to
     * @param string|null $email
     * @param string|null $phone
     * @param int|null $amountFrom
     * @param int|null $amountTo
     * @param Currency|null $currency
     * @param mixed $metadata
     * @param AppTokenMode|null $mode
     * @param string|null $transactionTokenId
     * @param string|null $gatewayCredentialsId
     * @param string|null $gatewayTransactionId
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return Paginated
     */
    public function listCharges(
        $lastFour = null,
        $name = null,
        $expMonth = null,
        $expYear = null,
        $cardNumber = null,
        ?DateTime $from = null,
        ?DateTime $to = null,
        $email = null,
        $phone = null,
        $amountFrom = null,
        $amountTo = null,
        ?Currency $currency = null,
        $metadata = null,
        ?AppTokenMode $mode = null,
        $transactionTokenId = null,
        $gatewayCredentialsId = null,
        $gatewayTransactionId = null,
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        $query = FunctionalUtils::stripNulls([
            'last_four' => $lastFour,
            'name' => $name,
            'exp_month' => $expMonth,
            'exp_year' => $expYear,
            'card_number' => $cardNumber,
            'from' => isset($from) ? $from->format(DateTime::ATOM) : null,
            'to' => isset($to) ? $to->format(DateTime::ATOM) : null,
            'email' => $email,
            'phone' => $phone,
            'amount_from' => $amountFrom,
            'amount_to' => $amountTo,
            'currency' => isset($currency) ? $currency->getCode() : null,
            'metadata' => $metadata,
            'mode' => isset($mode) ? $mode->getValue() : null,
            'transaction_token_id' => $transactionTokenId,
            'cursor' => $cursor,
            'limit' => $limit,
            'cursor_direction' => isset($cursorDirection) ? $cursorDirection->getValue() : null,
        ]);
        return $this->listChargesPage($query);
    }

    /**
     * @param array $opts See listCharges() parameters for valid opts keys.
     * @return Paginated
     */
    public function listChargesByOptions(array $opts = [])
    {
        $rules = [
            'from' => 'ValidationHelper::getAtomDate',
            'to' => 'ValidationHelper::getAtomDate',
            'currency' => 'ValidationHelper::getEnumValue',
            'mode' => 'ValidationHelper::getEnumValue',
            'cursor_direction' => 'ValidationHelper::getEnumValue',
        ];

        $query = $this->validate(FunctionalUtils::stripNulls($opts), $rules);
        return $this->listChargesPage($query);
    }
}
