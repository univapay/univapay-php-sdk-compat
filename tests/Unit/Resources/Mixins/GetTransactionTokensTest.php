<?php

namespace Univapay\Compat\Tests\Unit\Resources\Mixins;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Enums\ActiveFilter;
use Univapay\Compat\Enums\TokenType;
use Univapay\Compat\Errors\UnivapayValidationError;
use Univapay\Compat\Resources\Mixins\GetTransactionTokens;

/**
 * Covers the fix for old `listTransactionTokensByOptions()` parsing the response as
 * `Subscription`/`getSubscriptionContext()`, not as transaction tokens (see
 * `GetTransactionTokens`'s class doc): both call paths must reach the SAME
 * `listTransactionTokensPage()` hook.
 */
class GetTransactionTokensTest extends TestCase
{
    public function testListTransactionTokensBuildsTheExpectedQuery()
    {
        $fixture = new GetTransactionTokensFixture();

        $fixture->listTransactionTokens('search', 'cust-1', TokenType::RECURRING(), null, ActiveFilter::ACTIVE());

        $this->assertSame([
            'search' => 'search',
            'active' => 'active',
            'customer_id' => 'cust-1',
            'type' => 'recurring',
        ], $fixture->capturedQuery);
    }

    public function testListTransactionTokensRejectsOneTimeTypeFilter()
    {
        $fixture = new GetTransactionTokensFixture();

        $this->expectException(UnivapayValidationError::class);

        $fixture->listTransactionTokens(null, null, TokenType::ONE_TIME());
    }

    public function testListTransactionTokensByOptionsReachesTheSameHookAsThePositionalMethod()
    {
        // Old code reached Subscription::class / getSubscriptionContext() here instead.
        $fixture = new GetTransactionTokensFixture();

        $fixture->listTransactionTokensByOptions(['type' => TokenType::SUBSCRIPTION()]);
        // OptionsValidator::validate() installs a custom error handler and never restores it --
        // a preexisting ported quirk; see tests/Unit/Utility/OptionsValidatorTest.php.
        restore_error_handler();

        $this->assertSame(1, $fixture->hookCallCount);
        $this->assertSame('subscription', $fixture->capturedQuery['type']);
    }
}

class GetTransactionTokensFixture
{
    use GetTransactionTokens;

    /** @var array */
    public $capturedQuery;

    /** @var int */
    public $hookCallCount = 0;

    protected function listTransactionTokensPage(array $query)
    {
        $this->hookCallCount++;
        $this->capturedQuery = $query;
        return null;
    }
}
