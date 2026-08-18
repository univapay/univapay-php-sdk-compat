<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's `Resources\Configuration\Configuration`
 * -- the per-store/merchant configuration tree root, nested under `Merchant`/`Store`. Property
 * order (percentFee .. cardBrandPercentFees) already matches the old constructor, which
 * `Utility\Json\JsonSchema::fromClass()` relies on (see `Charge`'s class doc for why this matters
 * generally in this repo).
 */
class Configuration
{
    use Jsonable;

    public $percentFee;
    public $flatFees;
    public $logoUrl;
    public $country;
    public $language;
    public $displayTimeZone;
    public $minTransferPayout;
    public $maximumChargeAmounts;
    public $transferSchedule;
    public $userTransactionsConfiguration;
    public $cardConfiguration;
    public $qrScanConfiguration;
    public $convenienceConfiguration;
    public $paidyConfiguration;
    public $recurringTokenConfiguration;
    public $securityConfiguration;
    public $installmentsConfiguration;
    public $cardBrandPercentFees;

    public function __construct(
        $percentFee,
        $flatFees,
        $logoUrl,
        $country,
        $language,
        $displayTimeZone,
        $minTransferPayout,
        $maximumChargeAmounts,
        $transferSchedule,
        $userTransactionsConfiguration,
        $cardConfiguration,
        $qrScanConfiguration,
        $convenienceConfiguration,
        $paidyConfiguration,
        $recurringTokenConfiguration,
        $securityConfiguration,
        $installmentsConfiguration,
        $cardBrandPercentFees
    ) {
        $this->percentFee = $percentFee;
        $this->flatFees = $flatFees;
        $this->logoUrl = $logoUrl;
        $this->country = $country;
        $this->language = $language;
        $this->displayTimeZone = $displayTimeZone;
        $this->minTransferPayout = $minTransferPayout;
        $this->maximumChargeAmounts = $maximumChargeAmounts;
        $this->transferSchedule = $transferSchedule;
        $this->userTransactionsConfiguration = $userTransactionsConfiguration;
        $this->cardConfiguration = $cardConfiguration;
        $this->qrScanConfiguration = $qrScanConfiguration;
        $this->convenienceConfiguration = $convenienceConfiguration;
        $this->paidyConfiguration = $paidyConfiguration;
        $this->recurringTokenConfiguration = $recurringTokenConfiguration;
        $this->securityConfiguration = $securityConfiguration;
        $this->installmentsConfiguration = $installmentsConfiguration;
        $this->cardBrandPercentFees = $cardBrandPercentFees;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert(
                'transfer_schedule',
                false,
                TransferSchedule::getSchema()->getParser()
            )
            ->upsert(
                'user_transactions_configuration',
                true,
                UserTransactionsConfiguration::getSchema()->getParser()
            )
            ->upsert(
                'card_configuration',
                true,
                CardConfiguration::getSchema()->getParser()
            )
            ->upsert(
                'qr_scan_configuration',
                true,
                QrScanConfiguration::getSchema()->getParser()
            )
            ->upsert(
                'convenience_configuration',
                true,
                ConvenienceConfiguration::getSchema()->getParser()
            )
            ->upsert(
                'paidy_configuration',
                true,
                PaidyConfiguration::getSchema()->getParser()
            )
            ->upsert(
                'recurring_token_configuration',
                true,
                RecurringConfiguration::getSchema()->getParser()
            )
            ->upsert(
                'security_configuration',
                true,
                SecurityConfiguration::getSchema()->getParser()
            )
            ->upsert(
                'installments_configuration',
                true,
                InstallmentsConfiguration::getSchema()->getParser()
            )
            ->upsert(
                'card_brand_percent_fees',
                true,
                CardBrandPercentFees::getSchema()->getParser()
            );
    }
}
