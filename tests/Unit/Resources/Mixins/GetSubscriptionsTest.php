<?php

namespace Univapay\Compat\Tests\Unit\Resources\Mixins;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Enums\SubscriptionStatus;
use Univapay\Compat\Resources\Mixins\GetSubscriptions;
use Univapay\Compat\Tests\Support\NoticesDisabledBridgeStub;

class GetSubscriptionsTest extends TestCase
{
    public function testListSubscriptionsBuildsTheExpectedSnakeCaseQuery()
    {
        $fixture = new GetSubscriptionsFixture();

        $fixture->listSubscriptions(
            'search-term',
            SubscriptionStatus::CURRENT(),
            AppTokenMode::TEST(),
            'cur-1',
            25,
            CursorDirection::ASC()
        );

        $this->assertSame([
            'search' => 'search-term',
            'status' => 'current',
            'mode' => 'test',
            'cursor' => 'cur-1',
            'limit' => 25,
            'cursor_direction' => 'asc',
        ], $fixture->capturedQuery);
    }

    public function testListSubscriptionsByOptionsValidatesEnumOptions()
    {
        $fixture = new GetSubscriptionsFixture();

        $fixture->listSubscriptionsByOptions(['status' => SubscriptionStatus::CANCELED()]);
        // OptionsValidator::validate() installs a custom error handler and never restores it --
        // a preexisting ported quirk; see tests/Unit/Utility/OptionsValidatorTest.php.
        restore_error_handler();

        $this->assertSame('canceled', $fixture->capturedQuery['status']);
    }
}

class GetSubscriptionsFixture
{
    use GetSubscriptions;

    /** @var array */
    public $capturedQuery;

    protected function getBridge()
    {
        return new NoticesDisabledBridgeStub();
    }

    protected function nativeListSubscriptionsEquivalent(): string
    {
        return 'SubscriptionsApi::listAllSubscriptions()';
    }

    protected function listSubscriptionsPage(array $query)
    {
        $this->capturedQuery = $query;
        return null;
    }
}
