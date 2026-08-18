<?php

namespace Univapay\Compat\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use UnivaPay\Exceptions\ApiErrorException;
use UnivaPay\Exceptions\ApiException;
use UnivaPay\Http\HttpRequest;
use UnivaPay\Http\HttpResponse;
use UnivaPay\Models\ApiErrorDetail;
use Univapay\Compat\Errors\UnivapayForbiddenError;
use Univapay\Compat\Errors\UnivapayNetworkError;
use Univapay\Compat\Errors\UnivapayNotFoundError;
use Univapay\Compat\Errors\UnivapayRateLimitedError;
use Univapay\Compat\Errors\UnivapayRequestError;
use Univapay\Compat\Errors\UnivapayResourceConflictError;
use Univapay\Compat\Errors\UnivapayServerError;
use Univapay\Compat\Errors\UnivapayUnauthorizedError;
use Univapay\Compat\Support\ExceptionMapper;

/**
 * Constructs `\UnivaPay\Exceptions\ApiException` / `ApiErrorException` directly (rather than
 * going through a real HTTP call) for every row of the ExceptionMapper table, plus the code-0
 * network-error special case. See Support\ExceptionMapper's docblock for the table this mirrors.
 */
class ExceptionMapperTest extends TestCase
{
    private function request($url = 'https://api.univapay.com/charges/abc123')
    {
        return new HttpRequest('POST', [], $url, []);
    }

    private function errorException($statusCode, $codeProperty, $status = 'error', array $errors = [])
    {
        $response = new HttpResponse($statusCode, [], '{}');
        $request = $this->request();
        $exception = new ApiErrorException("HTTP $statusCode", $request, $response, $codeProperty);
        $exception->setStatus($status);

        $detailObjects = array_map(function ($e) {
            $detail = new ApiErrorDetail();
            $detail->setField($e['field']);
            $detail->setReason($e['reason']);
            return $detail;
        }, $errors);
        $exception->setErrors($detailObjects);

        return $exception;
    }

    public function testMapsHttp400ToUnivapayRequestErrorViaFromJson()
    {
        $exception = $this->errorException(400, 'VALIDATION_ERROR', 'error', [
            ['field' => 'amount', 'reason' => 'REQUIRED_VALUE']
        ]);

        $mapped = ExceptionMapper::map($exception);

        $this->assertInstanceOf(UnivapayRequestError::class, $mapped);
        $this->assertSame('error', $mapped->status);
        $this->assertSame('VALIDATION_ERROR', $mapped->code);
        $this->assertCount(1, $mapped->errors);
        $this->assertSame($this->request()->getQueryUrl(), $mapped->url);
    }

    public function testMapsHttp401ToUnivapayUnauthorizedError()
    {
        $exception = $this->errorException(401, 'AUTH_HEADER_MISSING');
        $mapped = ExceptionMapper::map($exception);

        $this->assertInstanceOf(UnivapayUnauthorizedError::class, $mapped);
        $this->assertSame('AUTH_HEADER_MISSING', $mapped->code);
    }

    public function testMapsHttp403ToUnivapayForbiddenError()
    {
        $exception = $this->errorException(403, 'INVALID_PERMISSIONS');
        $mapped = ExceptionMapper::map($exception);

        $this->assertInstanceOf(UnivapayForbiddenError::class, $mapped);
        $this->assertSame('INVALID_PERMISSIONS', $mapped->code);
    }

    public function testMapsHttp404ToUnivapayNotFoundErrorFromAPlainApiException()
    {
        // Per the generated code, 404 is a PLAIN ApiException -- not ApiErrorException. This
        // is the shape the mapper must handle without calling any ApiErrorException-only
        // accessor.
        $request = $this->request('https://api.univapay.com/stores/s1/charges/missing');
        $exception = new ApiException('Not Found', $request, new HttpResponse(404, [], ''));

        $mapped = ExceptionMapper::map($exception);

        $this->assertInstanceOf(UnivapayNotFoundError::class, $mapped);
        $this->assertStringContainsString(
            'https://api.univapay.com/stores/s1/charges/missing',
            $mapped->getMessage()
        );
    }

    public function testMapsHttp409ToUnivapayResourceConflictError()
    {
        $exception = $this->errorException(409, 'IDEMPOTENCY_KEY_CONFLICT');
        $mapped = ExceptionMapper::map($exception);

        $this->assertInstanceOf(UnivapayResourceConflictError::class, $mapped);
        $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $mapped->code);
    }

    public function testMapsHttp429ToUnivapayRateLimitedErrorAsAPlainApiException()
    {
        // 429 is not in the ApiErrorException-producing set either (400/401/403 only) --
        // exercise it as a plain ApiException, same as 404.
        $request = $this->request();
        $exception = new ApiException('Too Many Requests', $request, new HttpResponse(429, [], ''));

        $mapped = ExceptionMapper::map($exception);

        $this->assertInstanceOf(UnivapayRateLimitedError::class, $mapped);
    }

    public function testMapsUnmappedServerErrorStatusToUnivapayServerError()
    {
        $request = $this->request();
        $exception = new ApiException('Internal Server Error', $request, new HttpResponse(500, [], ''));

        $mapped = ExceptionMapper::map($exception);

        $this->assertInstanceOf(UnivapayServerError::class, $mapped);
        $this->assertStringContainsString('500', $mapped->getMessage());
    }

    public function testMapsCodeZeroWithNoResponseToUnivapayNetworkError()
    {
        $request = $this->request();
        $exception = new ApiException('Connection refused', $request, null);

        $this->assertSame(0, $exception->getCode());
        $this->assertFalse($exception->hasResponse());

        $mapped = ExceptionMapper::map($exception);

        $this->assertInstanceOf(UnivapayNetworkError::class, $mapped);
        $this->assertSame($request->getQueryUrl(), $mapped->url);
        $this->assertStringContainsString('Connection refused', $mapped->getMessage());
    }
}
