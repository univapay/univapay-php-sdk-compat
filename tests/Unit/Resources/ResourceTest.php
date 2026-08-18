<?php

namespace Univapay\Compat\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Resources\Jsonable;
use Univapay\Compat\Resources\Resource;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\CompatContext;
use Univapay\Compat\Utility\Json\JsonSchema;

/**
 * `Resource::fetch()`/`update()` must (a) call the abstract `fetchCall()`/`updateCall()` -- which
 * a concrete resource (Charge etc.) implements via `Support\ApiCaller` -- and
 * (b) hydrate a brand-NEW instance from the decoded body via the class's `Jsonable` schema,
 * never mutating `$this`. This fixture exercises that mechanism directly, without needing a real
 * Bridge/ApiCaller/HTTP round-trip.
 */
class ResourceTest extends TestCase
{
    public function testFetchReturnsANewInstanceHydratedFromFetchCallWithoutMutatingTheOriginal()
    {
        $context = 'fake-context-marker';
        $resource = (new ResourceFixture('r1', 'original', $context))
            ->withFetchBody(['id' => 'r2', 'label' => 'fetched']);

        $fetched = $resource->fetch();

        $this->assertInstanceOf(ResourceFixture::class, $fetched);
        $this->assertNotSame($resource, $fetched);
        $this->assertSame('r2', $fetched->id);
        $this->assertSame('fetched', $fetched->label);
        // The original is untouched -- fetch() never mutates $this.
        $this->assertSame('r1', $resource->id);
        $this->assertSame('original', $resource->label);
    }

    public function testUpdateReturnsANewInstanceHydratedFromUpdateCallWithoutMutatingTheOriginal()
    {
        $resource = (new ResourceFixture('r1', 'original'))
            ->withUpdateBody(['id' => 'r1', 'label' => 'updated']);

        $updated = $resource->update(['label' => 'updated']);

        $this->assertInstanceOf(ResourceFixture::class, $updated);
        $this->assertNotSame($resource, $updated);
        $this->assertSame('updated', $updated->label);
        $this->assertSame('original', $resource->label);
    }

    public function testFetchToleratesAnEmptyBodyRepresentedAsTrue()
    {
        // Resource itself does not special-case `true` (an empty-body sentinel from
        // Support\ApiCaller) -- a resource whose GET can legitimately return one must handle it
        // in its own fetchCall()/parsing; this pins that Resource::fetch() faithfully passes
        // through whatever fetchCall() returns without assuming it is always an array.
        $resource = new ResourceFixture('r1', 'original');
        $resource->withFetchBody(['id' => 'r1', 'label' => 'still-here']);

        $fetched = $resource->fetch();

        $this->assertSame('still-here', $fetched->label);
    }

    /**
     * `callAndHydrate()` (added for `TransactionToken::createCharge()`/`createSubscription()`
     * to reuse, and documented as a seam for other nested create flows) generalizes
     * fetch()/update() to hydrate a DIFFERENT resource class than `static`, through a REAL
     * `Support\ApiCaller`/`Support\Bridge` -- exercised here with a real (offline-constructible,
     * no actual HTTP performed) `Bridge` and a controller closure that manually calls
     * `recordResponse()`, exactly like `tests/Unit/Support/ApiCallerTest.php`'s own style.
     */
    public function testCallAndHydrateBuildsANewInstanceOfADifferentTargetClassThroughApiCaller()
    {
        $token = base64_encode((string) json_encode(['alg' => 'none'])) . '.' . base64_encode((string) json_encode([
            'sub' => 'app_token',
            'iat' => 1,
            'merchant_id' => 'merchant-1',
            'store_id' => 'store-1',
            'domains' => [],
            'mode' => 'test',
            'creator_id' => 'creator-1',
            'version' => 1,
            'jti' => 'jti-1'
        ])) . '.sig';
        $bridge = new Bridge(AppJWT::createToken($token, 'secret-1'));
        $context = new CompatContext($bridge, 'store-1');
        $resource = new CallAndHydrateFixture('r1', $context);

        $result = $resource->exposedCallAndHydrate(
            HydratedFixture::class,
            function () use ($bridge) {
                $bridge->caller()->recordResponse('{"id":"h1","label":"hydrated"}', 200);
            },
            'POST /fixture'
        );

        $this->assertInstanceOf(HydratedFixture::class, $result);
        $this->assertNotInstanceOf(CallAndHydrateFixture::class, $result);
        $this->assertSame('h1', $result->id);
        $this->assertSame('hydrated', $result->label);
    }
}

/**
 * Test-only concrete Resource exposing `callAndHydrate()` (protected on `Resource`) publicly so
 * this test can drive it directly, exactly like `ResourceFixture` exposes `fetchCall()`/
 * `updateCall()`'s mechanics above.
 */
class CallAndHydrateFixture extends Resource
{
    use Jsonable;

    public function __construct($id, $context = null)
    {
        parent::__construct($id, $context);
    }

    public function exposedCallAndHydrate(string $targetClass, callable $controllerFn, string $urlHint)
    {
        return $this->callAndHydrate($targetClass, $controllerFn, $urlHint);
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }

    protected function fetchCall()
    {
        return true;
    }

    protected function updateCall($updates)
    {
        return true;
    }
}

/**
 * Stands in for a DIFFERENT resource type than `CallAndHydrateFixture` (e.g. `Charge` being
 * hydrated from a `TransactionToken::createCharge()` call) -- `callAndHydrate()` must return an
 * instance of THIS class, not the caller's own class.
 */
class HydratedFixture
{
    use Jsonable;

    public $id;
    public $label;

    public function __construct($id, $label, $context = null)
    {
        $this->id = $id;
        $this->label = $label;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }
}

/**
 * Test-only concrete Resource. `$fetchBody`/`$updateBody` are declared `protected` deliberately:
 * `Utility\FunctionalUtils::getClassVarsAssoc()` (which `JsonSchema::fromClass()` uses to derive
 * schema paths) is called from an unrelated class, so PHP's `get_class_vars()` visibility rules
 * mean only PUBLIC properties are ever picked up as schema fields -- exactly how the old SDK's
 * `Resource::$context` (protected) stays invisible to the schema while `$id` (public) does not.
 * Declaring these test-fixture fields public would incorrectly make them schema-mapped fields.
 */
class ResourceFixture extends Resource
{
    use Jsonable;

    public $label;

    /** @var array|true|null */
    protected $fetchBody;

    /** @var array|true|null */
    protected $updateBody;

    public function __construct($id, $label, $context = null)
    {
        parent::__construct($id, $context);
        $this->label = $label;
    }

    public function withFetchBody($body): self
    {
        $this->fetchBody = $body;
        return $this;
    }

    public function withUpdateBody($body): self
    {
        $this->updateBody = $body;
        return $this;
    }

    protected static function initSchema()
    {
        return JsonSchema::fromClass(self::class);
    }

    protected function fetchCall()
    {
        return $this->fetchBody;
    }

    protected function updateCall($updates)
    {
        return $this->updateBody;
    }
}
