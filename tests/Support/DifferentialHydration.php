<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Support;

use UnivaPay\ApiHelper;
use Univapay\Compat\Resources\Authentication\AppJWT;
use Univapay\Compat\Support\Bridge;
use Univapay\Compat\Support\CompatContext;
use Univapay\Compat\Support\FallbackRegistry;
use Univapay\Compat\Support\TypedHydrator;
use Univapay\Compat\Support\TypedResult;

/**
 * The differential hydration harness: for a given raw fixture body, hydrates the SAME payload two
 * ways --
 *
 * 1. **Raw path**: `$targetClass::getSchema()->parse($rawBody, [$context])`, exactly as every
 *    resource hydrated before typed-first hydration existed.
 * 2. **Typed path**: deserializes $rawBody into `$generatedModelClass` via the SAME jsonmapper the
 *    real generated `Apis\*` controllers use (`UnivaPay\ApiHelper::getJsonHelper()->mapClass()` --
 *    see that class's own doc; this is what `ApiResponse::getResult()` is built from internally),
 *    then resolves it through `Support\TypedHydrator::resolve()` -- the exact same dispatch every
 *    flipped resource's real transport wiring uses.
 *
 * A resource is safe to flip to typed-primary only when these two are asserted equal for every
 * realistic fixture, AND fixtures that genuinely can't map to the typed model are shown to still
 * land on the raw result via the fallback (see `assertFallsBackToRaw()`).
 */
trait DifferentialHydration
{
    private function differentialContext(): CompatContext
    {
        $header = base64_encode((string) json_encode(['alg' => 'none']));
        $payload = base64_encode((string) json_encode([
            'sub' => 'app_token',
            'iat' => 1,
            'merchant_id' => 'merchant-1',
            'store_id' => 'store-1',
            'domains' => [],
            'mode' => 'test',
            'creator_id' => 'creator-1',
            'version' => 1,
            'jti' => 'jti-1',
        ]));
        $jwt = AppJWT::createToken("$header.$payload.sig", 'secret-1');
        return new CompatContext(new Bridge($jwt), 'store-1');
    }

    /**
     * @param string $targetClass Compat resource FQCN (uses Jsonable, has hydrateFromTyped()).
     * @param string $generatedModelClass Generated SDK model FQCN this response deserializes to.
     * @param array $rawBody The fixture, as a PHP array -- an empty JSON *object* field (e.g.
     *        `metadata`) must be authored as `(object) []`/`new \stdClass()`, never a bare `[]`,
     *        the same way real wire JSON distinguishes `{}` from `[]`; see toWireJson()'s doc.
     * @param CompatContext|null $context
     */
    private function assertTypedMatchesRaw(
        string $targetClass,
        string $generatedModelClass,
        array $rawBody,
        ?CompatContext $context = null
    ): void {
        $context = $context ?? $this->differentialContext();
        $wireJson = self::toWireJson($rawBody);

        // Both paths decode from the SAME wire bytes ApiCaller would have captured, exactly like
        // production: assoc=true for the raw path (what ApiCaller::decodeCapturedBody() produces),
        // assoc=false for the typed path (what Core\Response\Context::toApiResponseWithMappedType()
        // feeds the jsonmapper). Re-decoding from the wire string (not re-using $rawBody directly)
        // is what makes an empty-object field decode correctly for both instead of ambiguously.
        $rawDecoded = json_decode($wireJson, true);
        $objectTree = json_decode($wireJson, false);

        $rawObject = $targetClass::getSchema()->parse($rawDecoded, [$context]);

        $typed = ApiHelper::getJsonHelper()->mapClass($objectTree, $generatedModelClass);
        $result = new TypedResult($rawDecoded, $typed, false);
        $typedObject = TypedHydrator::resolve($targetClass, $result, $context);

        $this->assertEquals(
            $rawObject,
            $typedObject,
            "$targetClass: typed-path hydration diverged from the raw path for this fixture."
        );
    }

    /**
     * Encodes $value to a JSON string standing in for real wire bytes. PHP has one array type for
     * both JSON arrays and objects, so an empty `[]` in $value is genuinely ambiguous -- author an
     * empty JSON *object* field as `(object) []`/`new \stdClass()` in the fixture so `json_encode()`
     * emits `{}`, matching what the real API sends for an object-typed field (e.g. `metadata`) with
     * no entries, never a bare `[]`.
     */
    private static function toWireJson(array $value): string
    {
        return (string) json_encode($value);
    }

    /**
     * Asserts that hydrating $rawBody through `TypedHydrator::resolve()` -- given a $typed value
     * that can't/won't produce a hydration (null, or a genuinely mismatched shape) -- falls back to
     * the identical raw-parsed object, and that the fallback was actually recorded.
     *
     * @param mixed $typed What a real jsonmapper attempt produced for this fixture (null to
     *        simulate the jsonmapper itself throwing).
     */
    private function assertFallsBackToRaw(
        string $targetClass,
        array $rawBody,
        $typed = null,
        ?CompatContext $context = null
    ): void {
        $context = $context ?? $this->differentialContext();
        $rawObject = $targetClass::getSchema()->parse($rawBody, [$context]);

        FallbackRegistry::reset();
        $result = new TypedResult($rawBody, $typed, $typed === null);
        $fellBackObject = TypedHydrator::resolve($targetClass, $result, $context);

        $this->assertEquals($rawObject, $fellBackObject);
        $this->assertNotEmpty(
            FallbackRegistry::occurrences(),
            'Expected TypedHydrator to record a fallback occurrence for this fixture.'
        );
    }
}
