<?php

namespace Univapay\Compat\Resources;

use Univapay\Compat\Enums\Reason;
use Univapay\Compat\Utility\FunctionalUtils;
use Univapay\Compat\Errors\UnivapayLogicError;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\ThreeDSMPI`. Property order
 * (authenticationValue, eci, dsTransactionId, serverTransactionId, messageVersion,
 * transactionStatus) already matches the constructor. Carries the MPI fields the new SDK's
 * generated `ChargeCreateRequestThreeDs` model does not have (see `PaymentThreeDS`'s docblock
 * and plan blocker 3) -- this class's `jsonSerialize()` output is exactly what
 * `Support\RequestModelFactory::chargeCreate()` passes through via
 * `ChargeCreateRequest::addAdditionalProperty`-adjacent reflection, never a typed setter.
 */
class ThreeDSMPI
{
    use Jsonable;

    public $authenticationValue;
    public $eci;
    public $dsTransactionId;
    public $serverTransactionId;
    public $messageVersion;
    public $transactionStatus;

    public function __construct(
        $authenticationValue,
        $eci,
        $dsTransactionId,
        $serverTransactionId,
        $messageVersion,
        $transactionStatus
    ) {
        $this->authenticationValue = $authenticationValue;
        $this->eci = $eci;
        $this->dsTransactionId = $dsTransactionId;
        $this->serverTransactionId = $serverTransactionId;
        $this->messageVersion = $messageVersion;
        $this->transactionStatus = $transactionStatus;

        $this->validate();
    }

    private function validate()
    {
        $this->validateAllFieldsExist();
    }

    private function validateAllFieldsExist()
    {
        if (
            is_null($this->authenticationValue) || $this->authenticationValue === '' ||
            is_null($this->eci) || $this->eci === '' ||
            is_null($this->dsTransactionId) || $this->dsTransactionId === '' ||
            is_null($this->serverTransactionId) || $this->serverTransactionId === '' ||
            is_null($this->messageVersion) || $this->messageVersion === '' ||
            is_null($this->transactionStatus) || $this->transactionStatus === ''
        ) {
            throw new UnivapayLogicError(Reason::INCOMPLETE_THREE_DS_MPI_FIELDS());
        }
    }

    public function jsonSerialize()
    {
        return FunctionalUtils::stripNulls([
            'authentication_value' => $this->authenticationValue,
            'eci' => $this->eci,
            'ds_transaction_id' => $this->dsTransactionId,
            'server_transaction_id' => $this->serverTransactionId,
            'message_version' => $this->messageVersion,
            'transaction_status' => $this->transactionStatus
        ]);
    }
}
