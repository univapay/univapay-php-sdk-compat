<?php

namespace Univapay\Compat\Tests\Unit\Utility;

use DateTime;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Utility\OptionsValidator;

/**
 * Ported from the old SDK's tests/Univapay/Integration/OptionsValidatorTest.php (namespace and
 * FQCN updates only -- the assertions and rule shapes are unchanged).
 */
class OptionsValidatorTest extends TestCase
{
    use OptionsValidator;

    /**
     * OptionsValidator::validate() calls set_error_handler() (a PHP 5.x workaround, ported
     * verbatim) but never restore_error_handler() -- a preexisting quirk in the ported trait,
     * not something introduced here or safe to "fix" in src/. Modern PHPUnit (10+) marks a test
     * "risky" if it leaves a custom error handler installed, so this tearDown restores the
     * previous handler on the TEST side only, leaving the trait itself untouched.
     */
    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }

    public function testOptionValidator()
    {
        $rules = [
            'date' => 'ValidationHelper::getAtomDate',
            'enum' => 'ValidationHelper::getEnumValue',
            'array' => 'ValidationHelper::isArray'
        ];

        $date = date_create();
        $opts = [
            'date' => $date,
            'enum' => ChargeStatus::PENDING(),
            'array' => ['foo', 'bar'],
            'notValidated' => 'foobar'
        ];

        $validated = $this->validate($opts, $rules);
        $this->assertEquals($date->format(DateTime::ATOM), $validated['date']);
        $this->assertEquals(ChargeStatus::PENDING()->getValue(), $validated['enum']);
        $this->assertEquals(['foo', 'bar'], $validated['array']);
        $this->assertEquals('foobar', $validated['notValidated']);
    }

    public function testOptionValidationError()
    {
        $rules = [
            'date' => 'ValidationHelper::getAtomDate',
            'enum' => 'ValidationHelper::getEnumValue',
            'array' => 'ValidationHelper::isArray'
        ];

        $opts = [
            'enum' => 'pending',
            'notValidated' => 'foobar'
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->validate($opts, $rules);
    }
}
