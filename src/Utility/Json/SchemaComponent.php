<?php

namespace Univapay\Compat\Utility\Json;

/**
 * @internal
 */
class SchemaComponent
{
    public $path;
    public $required;
    public $formatter;

    public function __construct($path, $required, $formatter)
    {
        $this->path = trim($path, '/');
        $this->required = $required;
        $this->formatter = $formatter;
    }
}
