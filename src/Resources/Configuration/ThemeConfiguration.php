<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use UnivaPay\Models\CheckoutTheme;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\ThemeConfiguration`. `CheckoutInfo`-only -- backed by the generated
 * `CheckoutTheme`.
 */
class ThemeConfiguration
{
    use Jsonable;

    public $colors;

    public function __construct($colors)
    {
        $this->colors = $colors;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class)
            ->upsert('colors', true, ColorsConfiguration::getSchema()->getParser());
    }

    /**
     * Called directly by `Resources\CheckoutInfo::hydrateFromTyped()`. Declines when `colors`
     * (required=true in this class's own schema) is absent from the typed model, or when
     * `ColorsConfiguration::hydrateFromTyped()` itself declines.
     *
     * @param mixed $typed
     * @return self|null
     */
    public static function hydrateFromTyped($typed)
    {
        if (!($typed instanceof CheckoutTheme)) {
            return null;
        }
        $colorsTyped = $typed->getColors();
        if ($colorsTyped === null) {
            return null;
        }
        $colors = ColorsConfiguration::hydrateFromTyped($colorsTyped);
        if ($colors === null) {
            return null;
        }
        return new self($colors);
    }
}
