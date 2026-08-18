<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use DateTime;
use Money\Currency;
use Univapay\Compat\Enums\BankAccountStatus;
use Univapay\Compat\Enums\BankAccountType;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\BankAccount` (namespace lines + transport plumbing only --
 * public props are otherwise verbatim). Property order (primary .. createdOn -- `id` comes first
 * for free via the inherited `Resource::$id`) already matches the old constructor.
 *
 * UNSUPPORTED, PERMANENTLY: the new transport engine has no Bank Accounts API at all (no
 * `Apis\BankAccountsApi`, no `getBankAccount`/`listBankAccounts` generated controller methods
 * anywhere) -- `GET /bank_accounts` and `GET /bank_accounts/{id}` are not exposed.
 *
 * Still a full, hydration-capable data class (not a bare stub): parsing an already-received
 * `BankAccount`-shaped payload (e.g. from a caller's own stored data, or -- were the backend to
 * ever emit one -- a webhook) still works via `getSchema()->parse()`; only the HTTP-touching
 * `fetch()`/`update()` (both inherited from `Resource`) throw `UnivapayUnsupportedFeatureError`.
 * (Unlike `Transfer`, the old SDK's webhook events never actually carried a bank account payload --
 * `WebhookEvent` has no BANK_ACCOUNT_* case -- so this class's hydration capability exists purely
 * for parity with every other ported resource, not because a live webhook channel needs it.)
 */
class BankAccount extends Resource
{
    use Jsonable;

    public $primary;
    public $holderName;
    public $bankName;
    public $branchName;
    public $country;
    public $bankAddress;
    public $currency;
    public $accountNumber;
    public $routingNumber;
    public $swiftCode;
    public $ifscCode;
    public $routingCode;
    public $lastFour;
    public $status;
    public $accountType;
    public $createdOn;

    /**
     * @param mixed $id
     * @param mixed $primary
     * @param mixed $holderName
     * @param mixed $bankName
     * @param mixed $branchName
     * @param mixed $country
     * @param mixed $bankAddress
     * @param mixed $accountNumber
     * @param mixed $routingNumber
     * @param mixed $swiftCode
     * @param mixed $ifscCode
     * @param mixed $routingCode
     * @param mixed $lastFour
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $primary,
        $holderName,
        $bankName,
        $branchName,
        $country,
        $bankAddress,
        Currency $currency,
        $accountNumber,
        $routingNumber,
        $swiftCode,
        $ifscCode,
        $routingCode,
        $lastFour,
        BankAccountStatus $status,
        BankAccountType $accountType,
        DateTime $createdOn,
        $context = null
    ) {
        parent::__construct($id, $context);
        $this->primary = $primary;
        $this->holderName = $holderName;
        $this->bankName = $bankName;
        $this->branchName = $branchName;
        $this->country = $country;
        $this->bankAddress = $bankAddress;
        $this->currency = $currency;
        $this->accountNumber = $accountNumber;
        $this->routingNumber = $routingNumber;
        $this->swiftCode = $swiftCode;
        $this->ifscCode = $ifscCode;
        $this->routingCode = $routingCode;
        $this->lastFour = $lastFour;
        $this->status = $status;
        $this->accountType = $accountType;
        $this->createdOn = $createdOn;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('currency', true, FormatterUtils::of('getCurrency'))
            ->upsert('status', true, FormatterUtils::getTypedEnum(BankAccountStatus::class))
            ->upsert('account_type', true, FormatterUtils::getTypedEnum(BankAccountType::class))
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'));
    }

    protected function fetchCall()
    {
        throw new UnivapayUnsupportedFeatureError('BankAccount::fetch() (Bank Accounts)');
    }

    protected function updateCall($updates)
    {
        throw new UnivapayUnsupportedFeatureError('BankAccount::update() (Bank Accounts)');
    }
}
