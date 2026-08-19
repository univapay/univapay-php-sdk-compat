<?php

namespace Univapay\Compat\Tests\Unit\Resources\Mixins;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Resources\Mixins\GetCancels;
use Univapay\Compat\Resources\Mixins\GetRefunds;
use Univapay\Compat\Resources\Mixins\GetScheduledPayments;
use Univapay\Compat\Tests\Support\NoticesDisabledBridgeStub;

/**
 * `GetRefunds`, `GetCancels`, `GetScheduledPayments` all share the same cursor/limit/
 * cursor_direction (+ one extra filter for GetRefunds: metadata) query shape; covered together
 * for brevity. (`GetBankAccounts` used to be a fourth member of this group -- bank accounts are
 * PERMANENTLY unsupported, so it's a throw-only mixin like `GetTransfers`; covered in
 * UnsupportedMixinsTest instead, since it no longer builds a query at all.)
 */
class CursorOnlyMixinsTest extends TestCase
{
    public function testGetRefundsBuildsQueryIncludingMetadata()
    {
        $fixture = new GetRefundsFixture();

        $fixture->listRefunds('cur-1', 5, CursorDirection::DESC());

        $this->assertSame(['cursor' => 'cur-1', 'limit' => 5, 'cursor_direction' => 'desc'], $fixture->capturedQuery);
    }

    public function testGetRefundsByOptionsAcceptsMetadataPassThrough()
    {
        $fixture = new GetRefundsFixture();

        $fixture->listRefundsByOptions(['metadata' => 'order-42']);
        // OptionsValidator::validate() installs a custom error handler and never restores it --
        // a preexisting ported quirk; see tests/Unit/Utility/OptionsValidatorTest.php.
        restore_error_handler();

        $this->assertSame('order-42', $fixture->capturedQuery['metadata']);
    }

    public function testGetCancelsBuildsQuery()
    {
        $fixture = new GetCancelsFixture();

        $fixture->listCancels('cur-1', 5, CursorDirection::ASC());

        $this->assertSame(['cursor' => 'cur-1', 'limit' => 5, 'cursor_direction' => 'asc'], $fixture->capturedQuery);
    }

    public function testGetScheduledPaymentsBuildsQuery()
    {
        $fixture = new GetScheduledPaymentsFixture();

        $fixture->listScheduledPayments('cur-1', 5, CursorDirection::ASC());

        $this->assertSame(['cursor' => 'cur-1', 'limit' => 5, 'cursor_direction' => 'asc'], $fixture->capturedQuery);
    }
}

class GetRefundsFixture
{
    use GetRefunds;

    /** @var array */
    public $capturedQuery;

    protected function getBridge()
    {
        return new NoticesDisabledBridgeStub();
    }

    protected function listRefundsPage(array $query)
    {
        $this->capturedQuery = $query;
        return null;
    }
}

class GetCancelsFixture
{
    use GetCancels;

    /** @var array */
    public $capturedQuery;

    protected function getBridge()
    {
        return new NoticesDisabledBridgeStub();
    }

    protected function listCancelsPage(array $query)
    {
        $this->capturedQuery = $query;
        return null;
    }
}

class GetScheduledPaymentsFixture
{
    use GetScheduledPayments;

    /** @var array */
    public $capturedQuery;

    protected function getBridge()
    {
        return new NoticesDisabledBridgeStub();
    }

    protected function listScheduledPaymentsPage(array $query)
    {
        $this->capturedQuery = $query;
        return null;
    }
}
