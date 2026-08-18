<?php

declare(strict_types=1);

namespace Univapay\Compat\Resources\Configuration;

use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * Verbatim port (namespace lines only) of the old SDK's
 * `Resources\Configuration\ColorsConfiguration`. Same dead `FunctionalUtils as fp` import dropped
 * as `CardConfiguration` (see its class doc).
 */
class ColorsConfiguration
{
    use Jsonable;

    public $mainBackground;
    public $secondaryBackground;
    public $mainColor;
    public $mainText;
    public $primaryText;
    public $secondaryText;
    public $baseText;

    public function __construct(
        $mainBackground,
        $secondaryBackground,
        $mainColor,
        $mainText,
        $primaryText,
        $secondaryText,
        $baseText
    ) {
        $this->mainBackground = $mainBackground;
        $this->secondaryBackground = $secondaryBackground;
        $this->mainColor = $mainColor;
        $this->mainText = $mainText;
        $this->primaryText = $primaryText;
        $this->secondaryText = $secondaryText;
        $this->baseText = $baseText;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }
}
