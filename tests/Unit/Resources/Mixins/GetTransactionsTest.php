<?php

namespace Univapay\Compat\Tests\Unit\Resources\Mixins;

use DateTime;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Univapay\Compat\Enums\ChargeStatus;
use Univapay\Compat\Resources\Mixins\GetTransactions;

/**
 * Covers the fix for old `listTransactions()` unconditionally dereferencing
 * `$from->getTimestamp()`/`$to->getTimestamp()` even when left `null` (fataling on any
 * partial-filter call), and the per-method date format split: positional `listTransactions()` is
 * epoch-millis, `listTransactionsByOptions()` is ATOM -- same endpoint, two wire formats,
 * verbatim from the old SDK.
 */
class GetTransactionsTest extends TestCase
{
    /**
     * `card_brand`/`brands` are deprecated legacy aliases of `brand` (spec
     * `TransactionHistoryCardBrandQuery`/`TransactionHistoryBrandsQuery`, both now `deprecated:
     * true`). The old SDK's positional `listTransactions()` never had a brand filter parameter at
     * all -- this pins that it still doesn't, so this trait can never be the one to reintroduce a
     * legacy alias as a first-class positional argument (any future brand filter added here should
     * use `brand`, never `card_brand`/`brands`).
     */
    public function testListTransactionsHasNoPositionalBrandFilterParameter()
    {
        $reflected = new ReflectionMethod(GetTransactions::class, 'listTransactions');
        $paramNames = array_map(function ($p) {
            return $p->getName();
        }, $reflected->getParameters());

        $this->assertNotContains('brand', $paramNames);
        $this->assertNotContains('cardBrand', $paramNames);
        $this->assertNotContains('brands', $paramNames);
    }

    /**
     * `listTransactionsByOptions()` is a raw opts passthrough (see class doc) -- it never
     * normalizes or rewrites filter key names, so a caller-supplied `brand` key reaches
     * `Support\ListDispatcher::listTransactions()`'s `$query` completely unchanged. Pinned here so
     * a future validation rule accidentally rewriting `brand` to a deprecated alias (or vice
     * versa) would be caught immediately.
     */
    public function testListTransactionsByOptionsForwardsBrandFilterUnchanged()
    {
        $fixture = new GetTransactionsFixture();

        $fixture->listTransactionsByOptions(['brand' => ['visa']]);
        restore_error_handler();

        $this->assertSame(['visa'], $fixture->capturedQuery['brand']);
        $this->assertArrayNotHasKey('card_brand', $fixture->capturedQuery);
        $this->assertArrayNotHasKey('brands', $fixture->capturedQuery);
    }
    public function testListTransactionsWithBothDatesUsesEpochMillis()
    {
        $fixture = new GetTransactionsFixture();
        $from = new DateTime('2026-01-01T00:00:00+00:00');
        $to = new DateTime('2026-02-01T00:00:00+00:00');

        $fixture->listTransactions($from, $to);

        $this->assertSame($from->getTimestamp() * 1000, $fixture->capturedQuery['from']);
        $this->assertSame($to->getTimestamp() * 1000, $fixture->capturedQuery['to']);
    }

    public function testListTransactionsWithOnlyStatusDoesNotFatalOnNullDates()
    {
        // Old SDK fataled here: $from->getTimestamp() with $from === null is a fatal error on a
        // null method call. This exact call shape -- filtering by status alone -- is what a
        // caller reasonably expects an all-optional-parameters method to support.
        $fixture = new GetTransactionsFixture();

        $fixture->listTransactions(null, null, ChargeStatus::SUCCESSFUL());

        $this->assertArrayNotHasKey('from', $fixture->capturedQuery);
        $this->assertArrayNotHasKey('to', $fixture->capturedQuery);
        $this->assertSame('successful', $fixture->capturedQuery['status']);
    }

    public function testListTransactionsWithOnlyFromDoesNotFatalOnNullTo()
    {
        $fixture = new GetTransactionsFixture();
        $from = new DateTime('2026-01-01T00:00:00+00:00');

        $fixture->listTransactions($from);

        $this->assertSame($from->getTimestamp() * 1000, $fixture->capturedQuery['from']);
        $this->assertArrayNotHasKey('to', $fixture->capturedQuery);
    }

    public function testListTransactionsByOptionsUsesAtomFormatNotEpochMillis()
    {
        $fixture = new GetTransactionsFixture();

        $fixture->listTransactionsByOptions(['from' => new DateTime('2026-01-01T00:00:00+00:00')]);
        // OptionsValidator::validate() installs a custom error handler and never restores it --
        // a preexisting ported quirk; see tests/Unit/Utility/OptionsValidatorTest.php.
        restore_error_handler();

        $this->assertSame('2026-01-01T00:00:00+00:00', $fixture->capturedQuery['from']);
    }

    public function testGatewayCredentialsAndTransactionIdAreAcceptedButNeverAddedToTheQuery()
    {
        // Preexisting old-SDK dead-parameter behavior, preserved verbatim.
        $fixture = new GetTransactionsFixture();

        $fixture->listTransactions(null, null, null, null, null, null, 'gwcred-1', 'gwtx-1');

        $this->assertArrayNotHasKey('gateway_credentials_id', $fixture->capturedQuery);
        $this->assertArrayNotHasKey('gateway_transaction_id', $fixture->capturedQuery);
    }
}

class GetTransactionsFixture
{
    use GetTransactions;

    /** @var array */
    public $capturedQuery;

    protected function listTransactionsPage(array $query)
    {
        $this->capturedQuery = $query;
        return null;
    }
}
