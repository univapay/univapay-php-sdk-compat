<?php

namespace Univapay\Compat\Tests\Unit\Resources\Mixins;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Resources\Mixins\GetStores;

/**
 * Covers the fix for old `listStores()` calling `getCancelContext()` instead of `getStoreContext()`
 * (see `GetStores`'s class doc): both `listStores()` and `listStoresByOptions()` must reach the
 * SAME hook. There is no second, wrongly-named context getter left to assert against directly
 * (that is the point), so this test asserts the observable consequence: both call paths land on
 * `listStoresPage()`.
 */
class GetStoresTest extends TestCase
{
    public function testListStoresBuildsTheExpectedQueryAndReachesTheSingleHook()
    {
        $fixture = new GetStoresFixture();

        $fixture->listStores('cur-1', 10, CursorDirection::DESC());

        $this->assertSame(1, $fixture->hookCallCount);
        $this->assertSame(['cursor' => 'cur-1', 'limit' => 10, 'cursor_direction' => 'desc'], $fixture->capturedQuery);
    }

    public function testListStoresByOptionsReachesTheSameSingleHookNotAWrongOne()
    {
        $fixture = new GetStoresFixture();

        $fixture->listStoresByOptions(['cursor_direction' => CursorDirection::ASC()]);
        // OptionsValidator::validate() installs a custom error handler and never restores it --
        // a preexisting ported quirk; see tests/Unit/Utility/OptionsValidatorTest.php.
        restore_error_handler();

        $this->assertSame(1, $fixture->hookCallCount);
        $this->assertSame(['cursor_direction' => 'asc'], $fixture->capturedQuery);
    }

    public function testListStoresDropsUnsetOptionalArguments()
    {
        $fixture = new GetStoresFixture();

        $fixture->listStores();

        $this->assertSame([], $fixture->capturedQuery);
    }
}

class GetStoresFixture
{
    use GetStores;

    /** @var array */
    public $capturedQuery;

    /** @var int */
    public $hookCallCount = 0;

    protected function listStoresPage(array $query)
    {
        $this->hookCallCount++;
        $this->capturedQuery = $query;
        return null;
    }
}
