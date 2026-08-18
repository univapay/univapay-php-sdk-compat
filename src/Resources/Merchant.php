<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use DateTime;
use Univapay\Compat\Errors\UnivapayUnsupportedFeatureError;
use Univapay\Compat\Resources\Configuration\Configuration;
use Univapay\Compat\Utility\FormatterUtils;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Port of the old SDK's `Resources\Merchant` (namespace lines + transport plumbing only -- public
 * props are otherwise verbatim). Property order (verificationDataId .. createdOn) already matches
 * the old constructor.
 *
 * Old `Merchant` extends `Resource` but the old SDK's public surface never exposed a way to
 * `fetch()`/`update()` one directly -- `UnivapayClient::getMe()` is the only place a `Merchant` is
 * ever obtained, and the generated `Apis\MerchantsApi` only has `getCurrentMerchant()` (no
 * per-id GET, no PATCH at all). `fetchCall()`/`updateCall()` (required by `Resource`) therefore
 * both throw unconditionally -- there is no spec item this is pending on, since there was never a
 * per-id merchant endpoint to begin with, in either SDK.
 */
class Merchant extends Resource
{
    use Jsonable;

    public $verificationDataId;
    public $name;
    public $email;
    public $verified;
    public $configuration;
    public $createdOn;

    /**
     * @param mixed $id
     * @param mixed $verificationDataId
     * @param mixed $name
     * @param mixed $email
     * @param mixed $verified
     * @param mixed $configuration
     * @param \Univapay\Compat\Support\CompatContext|null $context
     */
    public function __construct(
        $id,
        $verificationDataId,
        $name,
        $email,
        $verified,
        $configuration,
        DateTime $createdOn,
        $context = null
    ) {
        parent::__construct($id, $context);
        $this->verificationDataId = $verificationDataId;
        $this->name = $name;
        $this->email = $email;
        $this->verified = $verified;
        $this->configuration = $configuration;
        $this->createdOn = $createdOn;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('configuration', true, Configuration::getSchema()->getParser())
            ->upsert('created_on', true, FormatterUtils::of('getDateTime'));
    }

    protected function fetchCall()
    {
        throw new UnivapayUnsupportedFeatureError(
            'Merchant::fetch() (no per-id merchant GET endpoint exists in either SDK -- use '
            . 'UnivapayClient::getMe() instead)'
        );
    }

    protected function updateCall($updates)
    {
        throw new UnivapayUnsupportedFeatureError('Merchant::update() (no merchant update endpoint exists)');
    }
}
