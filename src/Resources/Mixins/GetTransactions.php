<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Mixins;

use DateTime;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Enums\TransactionType;
use Univapay\Compat\Resources\Paginated;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Utility\OptionsValidator;

/**
 * Port of the old SDK's `Resources\Mixins\GetTransactions`. SUPPORTED: the trait itself is fully
 * ported here, and `Support\ListDispatcher::listTransactions()`/`listStoreTransactions()` dispatch
 * to the real `GET /transaction_history` endpoints this trait's query array is built for.
 *
 * `listTransactions()` is null-safe on `$from`/`$to`: passing only one, or neither (e.g.
 * `listTransactions(null, null, ChargeStatus::SUCCESSFUL())` to filter by status alone), no longer
 * fatals. Both are null-guarded, matching how every OTHER optional parameter in this same method
 * is already handled.
 *
 * `$gatewayCredentialsId`/`$gatewayTransactionId` are accepted but -- exactly as in the old
 * SDK -- never added to the query array below; see `GetCharges`'s class doc for why this
 * preexisting dead-parameter behavior is preserved rather than "fixed".
 */
trait GetTransactions
{
    use OptionsValidator;

    /**
     * @param array $query
     * @return Paginated
     */
    abstract protected function listTransactionsPage(array $query);

    /**
     * @param DateTime|null $from
     * @param DateTime|null $to
     * @param ChargeStatus|null $status
     * @param TransactionType|null $type
     * @param string|null $search
     * @param AppTokenMode|null $mode
     * @param string|null $gatewayCredentialsId
     * @param string|null $gatewayTransactionId
     * @param mixed $metadata
     * @param string|null $cursor
     * @param int|null $limit
     * @param CursorDirection|null $cursorDirection
     * @return Paginated
     */
    public function listTransactions(
        ?DateTime $from = null,
        ?DateTime $to = null,
        ?ChargeStatus $status = null,
        ?TransactionType $type = null,
        $search = null,
        ?AppTokenMode $mode = null,
        $gatewayCredentialsId = null,
        $gatewayTransactionId = null,
        $metadata = null,
        $cursor = null,
        $limit = null,
        ?CursorDirection $cursorDirection = null
    ) {
        $query = FunctionalUtils::stripNulls([
            // Null-guarded: fatals if dereferenced unconditionally when either date is left unset.
            'from' => isset($from) ? $from->getTimestamp() * 1000 : null,
            'to' => isset($to) ? $to->getTimestamp() * 1000 : null,
            'status' => isset($status) ? $status->getValue() : null,
            'type' => isset($type) ? $type->getValue() : null,
            'search' => $search,
            'mode' => isset($mode) ? $mode->getValue() : null,
            'metadata' => $metadata,
            'cursor' => $cursor,
            'limit' => $limit,
            'cursor_direction' => isset($cursorDirection) ? $cursorDirection->getValue() : null,
        ]);

        return $this->listTransactionsPage($query);
    }

    /**
     * @param array $opts See listTransactions() parameters for valid opts keys. Unlike the
     *        positional `listTransactions()` above (epoch-millis), THIS method expects `from`/`to`
     *        as `DateTime` instances and serializes them as ATOM strings -- the same endpoint, two
     *        different wire date formats depending on which method reached it, exactly as in the
     *        old SDK.
     * @return Paginated
     */
    public function listTransactionsByOptions(array $opts = [])
    {
        $rules = [
            'from' => 'ValidationHelper::getAtomDate',
            'to' => 'ValidationHelper::getAtomDate',
            'status' => 'ValidationHelper::getEnumValue',
            'type' => 'ValidationHelper::getEnumValue',
            'mode' => 'ValidationHelper::getEnumValue',
            'cursor_direction' => 'ValidationHelper::getEnumValue',
        ];

        $query = $this->validate(FunctionalUtils::stripNulls($opts), $rules);
        return $this->listTransactionsPage($query);
    }
}
