<?php

namespace Univapay\Compat\Tests\Unit\Support;

use apimatic\jsonmapper\JsonMapperException;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnivaPay\Exceptions\ApiException;
use UnivaPay\Http\HttpRequest;
use UnivaPay\Http\HttpResponse;
use Univapay\Compat\Errors\UnivapayError;
use Univapay\Compat\Errors\UnivapayNetworkError;
use Univapay\Compat\Errors\UnivapayNotFoundError;
use Univapay\Compat\Errors\UnivapayRateLimitedError;
use Univapay\Compat\Requests\Handlers\BasicRetryHandler;
use Univapay\Compat\Requests\Handlers\NetworkRetryHandler;
use Univapay\Compat\Requests\Handlers\RateLimitHandler;
use Univapay\Compat\Requests\Handlers\RequestHandler;
use Univapay\Compat\Support\ApiCaller;

/**
 * Covers plan blockers 1 (idempotency across retries) and 2 (raw-body capture bypassing strict
 * deserialization), the ExceptionMapper integration, and the "handler cascade contract" MAJOR
 * fix (nesting order + worst-case attempt multiplication). See ApiCaller's class doc for the
 * mechanism this exercises.
 */
class ApiCallerTest extends TestCase
{
    private function networkException($message = 'connection refused'): ApiException
    {
        return new ApiException($message, new HttpRequest('POST', [], 'https://api.univapay.com/charges', []), null);
    }

    private function notFoundException(): ApiException
    {
        $request = new HttpRequest('GET', [], 'https://api.univapay.com/stores/s1/charges/missing', []);
        return new ApiException('Not Found', $request, new HttpResponse(404, [], ''));
    }

    // --- Blocker 1: idempotency across retries -----------------------------------------------

    public function testSameIdempotencyKeyIsPassedToEveryRetryAttempt()
    {
        $caller = new ApiCaller();
        $handler = new RateLimitHandler(2, 0); // up to 3 total attempts
        $keysSeen = [];
        $attempt = 0;

        $result = $caller->call(function ($idempotencyKey) use (&$keysSeen, &$attempt, $caller) {
            $keysSeen[] = $idempotencyKey;
            $attempt++;
            if ($attempt < 3) {
                throw new UnivapayRateLimitedError('https://api.univapay.com/charges');
            }
            $caller->recordResponse('{"id":"abc"}', 200);
        }, [$handler], 'POST /charges');

        $this->assertSame(['id' => 'abc'], $result);
        $this->assertCount(3, $keysSeen);
        $this->assertNotEmpty($keysSeen[0]);
        $this->assertSame($keysSeen[0], $keysSeen[1]);
        $this->assertSame($keysSeen[0], $keysSeen[2]);
    }

    public function testEachLogicalCallGetsItsOwnFreshKey()
    {
        $caller = new ApiCaller();
        $seenKeys = [];

        for ($i = 0; $i < 2; $i++) {
            $caller->call(function ($idempotencyKey) use (&$seenKeys, $caller) {
                $seenKeys[] = $idempotencyKey;
                $caller->recordResponse('{}', 200);
            }, [], 'POST /charges');
        }

        $this->assertNotSame($seenKeys[0], $seenKeys[1]);
    }

    public function testGetAndDeleteCallersMayIgnoreTheIdempotencyKeyArgument()
    {
        $caller = new ApiCaller();

        $result = $caller->call(function () use ($caller) {
            // GET/DELETE closures are allowed to ignore the argument entirely.
            $caller->recordResponse('{"id":"abc"}', 200);
        }, [], 'GET /charges/abc');

        $this->assertSame(['id' => 'abc'], $result);
    }

    // --- Success-path body decoding ----------------------------------------------------------

    public function testDecodesA2xxBodyToAnAssociativeArray()
    {
        $caller = new ApiCaller();

        $result = $caller->call(function () use ($caller) {
            $caller->recordResponse('{"id":"c1","status":"pending"}', 201);
        }, [], 'POST /charges');

        $this->assertSame(['id' => 'c1', 'status' => 'pending'], $result);
    }

    public function testEmptyBodyDecodesToTrue()
    {
        // Matches old Utility\HttpUtils::checkResponse()'s "NOTE: json_decode would return null
        // for boolean false" handling of an empty body.
        $caller = new ApiCaller();

        $result = $caller->call(function () use ($caller) {
            $caller->recordResponse('', 204);
        }, [], 'POST /charges/c1/capture');

        $this->assertTrue($result);
    }

    // --- ExceptionMapper integration ---------------------------------------------------------

    public function testApiExceptionIsMappedThroughExceptionMapper()
    {
        $caller = new ApiCaller();

        $this->expectException(UnivapayNotFoundError::class);

        $caller->call(function () {
            throw $this->notFoundException();
        }, [], 'GET /stores/s1/charges/missing');
    }

    // --- Blocker 2: raw-body capture bypasses strict deserialization -------------------------

    public function testJsonMapperExceptionBypassesToTheCapturedRawBodyOnA2xxResponse()
    {
        $caller = new ApiCaller();

        $result = $caller->call(function () use ($caller) {
            // Simulates the generated client's afterResponse hook firing (capturing the raw
            // body) before its strict typed mapper throws on an unmodeled shape.
            $caller->recordResponse('{"id":"c1","metadata":{"nested":{"legacy":true}}}', 200);
            throw new JsonMapperException("JSON property 'nested' does not exist in object of type 'Metadata'");
        }, [], 'GET /stores/s1/charges/c1');

        $this->assertSame(['id' => 'c1', 'metadata' => ['nested' => ['legacy' => true]]], $result);
    }

    public function testJsonMapperExceptionWithoutA2xxCaptureIsRethrownWrapped()
    {
        $caller = new ApiCaller();

        try {
            $caller->call(function () {
                // No recordResponse() call at all -- nothing to bypass to.
                throw new JsonMapperException('mapping failed');
            }, [], 'GET /stores/s1/charges/c1');
            $this->fail('Expected a UnivapayError to be thrown');
        } catch (UnivapayError $e) {
            $this->assertStringContainsString('mapping failed', $e->getMessage());
            $this->assertInstanceOf(JsonMapperException::class, $e->getPrevious());
        }
    }

    public function testNonBypassableThrowableIsRethrownWrappedInAUnivapayError()
    {
        $caller = new ApiCaller();

        try {
            $caller->call(function () {
                throw new RuntimeException('totally unexpected');
            }, [], 'POST /charges');
            $this->fail('Expected a UnivapayError to be thrown');
        } catch (UnivapayError $e) {
            $this->assertStringContainsString('totally unexpected', $e->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    // --- Network-error retry path (blocker 4) ------------------------------------------------

    public function testNetworkErrorIsMappedAndRetriedByNetworkRetryHandler()
    {
        $caller = new ApiCaller();
        $handler = new NetworkRetryHandler(2, 0);
        $attempt = 0;

        $result = $caller->call(function () use (&$attempt, $caller) {
            $attempt++;
            if ($attempt < 3) {
                throw $this->networkException();
            }
            $caller->recordResponse('{"id":"c1"}', 200);
        }, [$handler], 'POST /charges');

        $this->assertSame(['id' => 'c1'], $result);
        $this->assertSame(3, $attempt);
    }

    public function testNetworkErrorExhaustsRetriesAndPropagatesAsUnivapayNetworkError()
    {
        $caller = new ApiCaller();
        $handler = new NetworkRetryHandler(1, 0);
        $attempt = 0;

        $this->expectException(UnivapayNetworkError::class);

        try {
            $caller->call(function () use (&$attempt) {
                $attempt++;
                throw $this->networkException();
            }, [$handler], 'POST /charges');
        } finally {
            $this->assertSame(2, $attempt); // 1 retried attempt + 1 mandatory final attempt
        }
    }

    // --- Handler cascade contract: nesting order + worst-case multiplication ----------------

    public function testOutermostHandlerIsTheLastArrayElementAndRunsFirst()
    {
        $log = [];
        $inner = new RecordingHandler('inner', $log);
        $outer = new RecordingHandler('outer', $log);
        $caller = new ApiCaller();

        $caller->call(function () use ($caller) {
            $caller->recordResponse('{}', 200);
        }, [$inner, $outer], 'GET /charges');

        $this->assertSame(['outer:before', 'inner:before', 'inner:after', 'outer:after'], $log);
    }

    public function testWorstCaseAttemptMultiplicationAcrossNestedRetryHandlers()
    {
        // Both target the same mapped error type so each layer's retry loop actually engages.
        // inner tries=2 -> 3 attempts per invocation from its caller's perspective;
        // outer tries=1 -> 2 invocations of the inner-wrapped closure.
        // Total physical calls = 3 * 2 = 6 (see plan "worst-case multiplication preserved").
        $inner = new BasicRetryHandler(UnivapayNetworkError::class, 2, 0);
        $outer = new BasicRetryHandler(UnivapayNetworkError::class, 1, 0);
        $caller = new ApiCaller();
        $calls = 0;

        $this->expectException(UnivapayNetworkError::class);

        try {
            $caller->call(function () use (&$calls) {
                $calls++;
                throw $this->networkException('still down');
            }, [$inner, $outer], 'POST /charges');
        } finally {
            $this->assertSame(6, $calls);
        }
    }
}

/**
 * Test-only handler that records cascade ordering instead of retrying anything. Kept in this
 * file per phpcs.xml's documented exception for test fixture support classes.
 */
class RecordingHandler implements RequestHandler
{
    private $label;

    /** @var array */
    private $log;

    public function __construct($label, array &$log)
    {
        $this->label = $label;
        $this->log = &$log;
    }

    public function handle(Closure $request, array $requestData)
    {
        $this->log[] = "{$this->label}:before";
        $result = $request($requestData);
        $this->log[] = "{$this->label}:after";
        return $result;
    }
}
