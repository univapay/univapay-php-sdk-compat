<?php

namespace Univapay\Compat\Tests\Unit\Resources\Mixins;

use DateTime;
use Money\Currency;
use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\AppTokenMode;
use Univapay\Compat\Enums\CursorDirection;
use Univapay\Compat\Resources\Mixins\GetCharges;
use Univapay\Compat\Tests\Support\NoticesDisabledBridgeStub;

/**
 * Query-array construction for `GetCharges`, isolated from `ListDispatcher`/`Bridge`/HTTP: the
 * fixture below captures whatever `listChargesPage()` receives instead of dispatching anywhere.
 */
class GetChargesTest extends TestCase
{
    private function fixture(): GetChargesFixture
    {
        return new GetChargesFixture();
    }

    public function testListChargesBuildsTheExpectedSnakeCaseQueryAndDropsNulls()
    {
        $fixture = $this->fixture();

        $fixture->listCharges(
            '4242',
            'Jane Doe',
            '01',
            '2030',
            null,
            new DateTime('2026-01-01T00:00:00+00:00'),
            new DateTime('2026-02-01T00:00:00+00:00'),
            'jane@example.com',
            null,
            100,
            null,
            new Currency('JPY'),
            'meta',
            AppTokenMode::LIVE(),
            'token-1',
            null,
            null,
            'cur-1',
            10,
            CursorDirection::ASC()
        );

        $this->assertSame([
            'last_four' => '4242',
            'name' => 'Jane Doe',
            'exp_month' => '01',
            'exp_year' => '2030',
            'from' => '2026-01-01T00:00:00+00:00',
            'to' => '2026-02-01T00:00:00+00:00',
            'email' => 'jane@example.com',
            'amount_from' => 100,
            'currency' => 'JPY',
            'metadata' => 'meta',
            'mode' => 'live',
            'transaction_token_id' => 'token-1',
            'cursor' => 'cur-1',
            'limit' => 10,
            'cursor_direction' => 'asc',
        ], $fixture->capturedQuery);
    }

    public function testGatewayCredentialsAndTransactionIdAreAcceptedButNeverAddedToTheQuery()
    {
        // Old-SDK dead-parameter behavior, preserved verbatim -- see GetCharges's class doc.
        $fixture = $this->fixture();

        $fixture->listCharges(
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'gwcred-1',
            'gwtx-1'
        );

        $this->assertArrayNotHasKey('gateway_credentials_id', $fixture->capturedQuery);
        $this->assertArrayNotHasKey('gateway_transaction_id', $fixture->capturedQuery);
    }

    public function testCardNumberIsStillBuiltIntoTheQueryHereEvenThoughNoGeneratedFilterExistsYet()
    {
        // GetCharges itself doesn't know about the spec gap -- ListDispatcher is what fails loud
        // on it (see ListDispatcherTest::testListAllChargesThrowsOnAKeyWithNoGeneratedEquivalent).
        $fixture = $this->fixture();

        $fixture->listCharges(null, null, null, null, '4111');

        $this->assertSame('4111', $fixture->capturedQuery['card_number']);
    }

    public function testListChargesByOptionsValidatesEnumAndDateOptionsAndPassesThroughUnknownKeys()
    {
        $fixture = $this->fixture();

        $fixture->listChargesByOptions([
            'from' => new DateTime('2026-01-01T00:00:00+00:00'),
            'mode' => AppTokenMode::LIVE(),
            'email' => 'jane@example.com',
        ]);
        // OptionsValidator::validate() installs a custom error handler and never restores it -- a
        // preexisting ported quirk, not something to "fix" in src/; see
        // tests/Unit/Utility/OptionsValidatorTest.php for the same pattern. Restored here (only
        // after the ...ByOptions() call that actually installs it) so this test isn't marked
        // risky by PHPUnit without falsely popping a handler THIS test never installed.
        restore_error_handler();

        $this->assertSame('2026-01-01T00:00:00+00:00', $fixture->capturedQuery['from']);
        $this->assertSame('live', $fixture->capturedQuery['mode']);
        $this->assertSame('jane@example.com', $fixture->capturedQuery['email']);
    }
}

/**
 * Test-only fixture implementing the abstract hook by capturing its argument instead of
 * dispatching. Kept in this file per phpcs.xml's documented exception for test fixture support
 * classes.
 */
class GetChargesFixture
{
    use GetCharges;

    /** @var array */
    public $capturedQuery;

    protected function getBridge()
    {
        return new NoticesDisabledBridgeStub();
    }

    protected function nativeListChargesEquivalent(): string
    {
        return 'ChargesApi::listAllCharges()';
    }

    protected function listChargesPage(array $query)
    {
        $this->capturedQuery = $query;
        return null;
    }
}
