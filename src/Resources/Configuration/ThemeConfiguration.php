<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\ThemeConfiguration`.
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
}
