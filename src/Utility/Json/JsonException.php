<?php

namespace Univapay\Compat\Utility\Json;

use Exception;

/**
 * @internal
 */
abstract class JsonException extends Exception
{
    public $path;

    public function __construct($path)
    {
        parent::__construct("Error at path $path");
        $this->path = $path;
    }
}
