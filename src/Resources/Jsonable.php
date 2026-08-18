<?php

namespace Univapay\Compat\Resources;

/**
 * Verbatim port (namespace line only) of the old SDK's `Resources\Jsonable`.
 *
 * `initSchema()` is required to be implemented by every using class but is deliberately left
 * undeclared here (rather than as an `abstract protected static function`), matching upstream:
 * declaring it abstract on a trait throws a fatal error under strict typing on the PHP versions
 * this package still supports. It is called dynamically via `self::initSchema()` instead, so a
 * class that forgets to define it fails at first use, not at parse time.
 */
trait Jsonable
{
    protected static $schema;

    // Required to be implemented but causes an exception to be thrown in strict mode on PHP >5.3 && <7
    // protected abstract static function initSchema();

    public static function getSchema()
    {
        if (!isset(self::$schema)) {
            self::$schema = self::initSchema();
        }
        return self::$schema;
    }

    public static function getContextParser($context)
    {
        return self::getSchema()->getParser([$context]);
    }
}
