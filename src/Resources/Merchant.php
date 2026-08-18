<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources;

use DateTime;
use UnivaPay\Models\Merchant as GeneratedMerchant;
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

    /**
     * Typed-first hydration entry point for `Support\TypedHydrator`. Every scalar field is a
     * clean 1:1 match against the generated SDK's `UnivaPay\Models\Merchant`; `configuration` is
     * dispatched to `Configuration::hydrateFromTyped()` (see its own doc for the nested
     * `Configuration\*` tree audit this relies on).
     *
     * Declines when `configuration`/`created_on` (both required=true in this class's own schema)
     * are missing from the typed model, or when `Configuration::hydrateFromTyped()` itself
     * declines (a required nested config missing).
     *
     * @param mixed $typed
     * @param array $body
     * @param \Univapay\Compat\Support\CompatContext|null $context
     * @return self|null
     */
    public static function hydrateFromTyped($typed, array $body, $context)
    {
        if (!($typed instanceof GeneratedMerchant)) {
            return null;
        }
        $configurationTyped = $typed->getConfiguration();
        $createdOn = $typed->getCreatedOn();
        if ($configurationTyped === null || $createdOn === null) {
            return null;
        }
        $configurationBody = isset($body['configuration']) && is_array($body['configuration'])
            ? $body['configuration']
            : [];
        $configuration = Configuration::hydrateFromTyped($configurationTyped, $configurationBody);
        if ($configuration === null) {
            return null;
        }

        return new self(
            $typed->getId(),
            $typed->getVerificationDataId(),
            $typed->getName(),
            $typed->getEmail(),
            $typed->getVerified(),
            $configuration,
            $createdOn,
            $context
        );
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
