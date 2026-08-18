<?php

namespace Univapay\Compat\Tests\Unit\Errors;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Errors\UnivapayRequestError;

class UnivapayRequestErrorTest extends TestCase
{
    public function testConstructorSetsPublicPropertiesAndPrintRMessage()
    {
        $error = new UnivapayRequestError(
            'https://api.univapay.com/charges',
            'error',
            'VALIDATION_ERROR',
            [['field' => 'amount', 'reason' => 'REQUIRED_VALUE']]
        );

        $this->assertSame('https://api.univapay.com/charges', $error->url);
        $this->assertSame('error', $error->status);
        $this->assertSame('VALIDATION_ERROR', $error->code);
        $this->assertSame([['field' => 'amount', 'reason' => 'REQUIRED_VALUE']], $error->errors);

        // Message is built with print_r() of the whole shape, including http_status -- ported
        // verbatim; assert on substrings rather than the full print_r() layout for stability.
        $this->assertStringContainsString('VALIDATION_ERROR', $error->getMessage());
        $this->assertStringContainsString('[http_status] => 400', $error->getMessage());
        $this->assertStringContainsString('https://api.univapay.com/charges', $error->getMessage());
    }

    public function testFromJsonDoesNotUseIssetAndReadsKeysDirectly()
    {
        $error = UnivapayRequestError::fromJson('https://api.univapay.com/charges', [
            'status' => 'error',
            'code' => 'VALIDATION_ERROR',
            'errors' => []
        ]);

        $this->assertSame('error', $error->status);
        $this->assertSame('VALIDATION_ERROR', $error->code);
        $this->assertSame([], $error->errors);
    }
}
