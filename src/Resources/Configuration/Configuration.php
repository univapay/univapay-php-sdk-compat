<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\MerchantWebhookConfiguration;
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

    /**
     * Called directly by `Resources\Merchant::hydrateFromTyped()`/`Resources\Store::
     * hydrateFromTyped()` (this class is never independently fetched via `Support\TypedHydrator::
     * resolve()`, only nested under those two -- both share this same generated
     * `UnivaPay\Models\MerchantWebhookConfiguration`).
     *
     * `flat_fees`/`min_transfer_payout`/`maximum_charge_amounts` are read from $body (this same
     * raw sub-object): compat has always stored these as the raw decoded value verbatim (no Money
     * conversion, no formatter at all in this class's own schema), so the typed model's own
     * `MerchantWebhookMoneyAmount`-shaped getters are bypassed entirely for these three, same
     * treatment as `Charge`'s `metadata`. `percent_fee`/`logo_url`/`country`/`language`/
     * `display_time_zone` are plain scalars with a clean typed counterpart, read directly.
     *
     * Declines (null) when a required nested configuration (`user_transactions_configuration`,
     * `card_configuration`, `qr_scan_configuration`, `convenience_configuration`,
     * `paidy_configuration`, `recurring_token_configuration`, `security_configuration`,
     * `installments_configuration`, `card_brand_percent_fees` -- all required=true in this class's
     * own schema) is missing from the typed model, or when that nested class's own
     * `hydrateFromTyped()` declines. `transfer_schedule` is the one optional nested field --
     * missing/unmappable resolves to null instead.
     *
     * @param mixed $typed
     * @param array $body
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body)
    {
        if (!($typed instanceof MerchantWebhookConfiguration)) {
            return null;
        }

        $userTransactions = self::requiredNested(
            UserTransactionsConfiguration::class,
            $typed->getUserTransactionsConfiguration()
        );
        $card = self::requiredNested(CardConfiguration::class, $typed->getCardConfiguration());
        $qrScan = self::requiredNestedWithBody(
            QrScanConfiguration::class,
            $typed->getQrScanConfiguration(),
            self::sub($body, 'qr_scan_configuration')
        );
        $convenience = self::requiredNested(ConvenienceConfiguration::class, $typed->getConvenienceConfiguration());
        $paidy = self::requiredNested(PaidyConfiguration::class, $typed->getPaidyConfiguration());
        $cardBrandPercentFees = self::requiredNested(
            CardBrandPercentFees::class,
            $typed->getCardBrandPercentFees()
        );

        $recurringTyped = $typed->getRecurringTokenConfiguration();
        $recurringBody = self::sub($body, 'recurring_token_configuration');
        $recurring = $recurringTyped !== null
            ? RecurringConfiguration::hydrateFromTyped($recurringTyped, $recurringBody)
            : null;

        $securityTyped = $typed->getSecurityConfiguration();
        $securityBody = self::sub($body, 'security_configuration');
        $security = $securityTyped !== null
            ? SecurityConfiguration::hydrateFromTyped($securityTyped, $securityBody)
            : null;

        $installmentsTyped = $typed->getInstallmentsConfiguration();
        $installmentsBody = self::sub($body, 'installments_configuration');
        $installments = $installmentsTyped !== null
            ? InstallmentsConfiguration::hydrateFromTyped($installmentsTyped, $installmentsBody)
            : null;

        if (
            $userTransactions === null || $card === null || $qrScan === null || $convenience === null
            || $paidy === null || $cardBrandPercentFees === null || $recurring === null || $security === null
            || $installments === null
        ) {
            return null;
        }

        $transferScheduleTyped = $typed->getTransferSchedule();
        $transferSchedule = $transferScheduleTyped !== null
            ? TransferSchedule::hydrateFromTyped($transferScheduleTyped)
            : null;

        return new self(
            $typed->getPercentFee(),
            array_key_exists('flat_fees', $body) ? $body['flat_fees'] : null,
            $typed->getLogoUrl(),
            $typed->getCountry(),
            $typed->getLanguage(),
            $typed->getDisplayTimeZone(),
            array_key_exists('min_transfer_payout', $body) ? $body['min_transfer_payout'] : null,
            array_key_exists('maximum_charge_amounts', $body) ? $body['maximum_charge_amounts'] : null,
            $transferSchedule,
            $userTransactions,
            $card,
            $qrScan,
            $convenience,
            $paidy,
            $recurring,
            $security,
            $installments,
            $cardBrandPercentFees
        );
    }

    /** @return array Raw sub-object at $key, or [] if absent/not an array. */
    private static function sub(array $body, string $key): array
    {
        return isset($body[$key]) && is_array($body[$key]) ? $body[$key] : [];
    }

    /**
     * Required-nested-config helper for classes whose `hydrateFromTyped($typed)` takes no $body.
     *
     * @param string $targetClass
     * @param mixed $typedSub
     * @return mixed|null
     */
    private static function requiredNested(string $targetClass, $typedSub)
    {
        if ($typedSub === null) {
            return null;
        }
        return call_user_func([$targetClass, 'hydrateFromTyped'], $typedSub);
    }

    /**
     * Required-nested-config helper for classes whose `hydrateFromTyped($typed, array $body)`
     * takes a raw sub-body too.
     *
     * @param string $targetClass
     * @param mixed $typedSub
     * @param array $subBody
     * @return mixed|null
     */
    private static function requiredNestedWithBody(string $targetClass, $typedSub, array $subBody)
    {
        if ($typedSub === null) {
            return null;
        }
        return call_user_func([$targetClass, 'hydrateFromTyped'], $typedSub, $subBody);
    }
}
