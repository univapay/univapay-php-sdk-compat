<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Support\FallbackRegistry;

class FallbackRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        FallbackRegistry::reset();
        FallbackRegistry::setHook(null);
    }

    protected function tearDown(): void
    {
        FallbackRegistry::reset();
        FallbackRegistry::setHook(null);
    }

    public function testRecordAppendsAnOccurrence()
    {
        FallbackRegistry::record('Some\\Resource', FallbackRegistry::REASON_JSONMAPPER_EXCEPTION, 'boom');

        $this->assertSame(
            [[
                'resource' => 'Some\\Resource',
                'reason' => FallbackRegistry::REASON_JSONMAPPER_EXCEPTION,
                'detail' => 'boom',
            ]],
            FallbackRegistry::occurrences()
        );
    }

    public function testDetailDefaultsToNull()
    {
        FallbackRegistry::record('Some\\Resource', FallbackRegistry::REASON_HYDRATION_DECLINED);

        $this->assertNull(FallbackRegistry::occurrences()[0]['detail']);
    }

    public function testResetClearsOccurrences()
    {
        FallbackRegistry::record('Some\\Resource', FallbackRegistry::REASON_HYDRATION_DECLINED);
        FallbackRegistry::reset();

        $this->assertSame([], FallbackRegistry::occurrences());
    }

    public function testHookIsInvokedWithTheSameEntry()
    {
        $seen = [];
        FallbackRegistry::setHook(function (array $entry) use (&$seen) {
            $seen[] = $entry;
        });

        FallbackRegistry::record('Some\\Resource', FallbackRegistry::REASON_HYDRATION_EXCEPTION, 'oops');

        $this->assertCount(1, $seen);
        $this->assertSame('Some\\Resource', $seen[0]['resource']);
        $this->assertSame(FallbackRegistry::REASON_HYDRATION_EXCEPTION, $seen[0]['reason']);
        $this->assertSame('oops', $seen[0]['detail']);
    }

    public function testSettingHookToNullStopsInvokingIt()
    {
        $calls = 0;
        FallbackRegistry::setHook(function () use (&$calls) {
            $calls++;
        });
        FallbackRegistry::setHook(null);

        FallbackRegistry::record('Some\\Resource', FallbackRegistry::REASON_HYDRATION_DECLINED);

        $this->assertSame(0, $calls);
        // But the occurrence itself is still recorded regardless of the hook.
        $this->assertCount(1, FallbackRegistry::occurrences());
    }

    public function testRecordingWithNoHookProducesNoOutputOrSideEffectsBeyondTheArray()
    {
        // Nothing here should throw, echo, or otherwise misbehave with the default (no hook)
        // configuration -- the "zero side effects for normal consumers" contract.
        $this->expectOutputString('');
        FallbackRegistry::record('Some\\Resource', FallbackRegistry::REASON_JSONMAPPER_EXCEPTION);
        $this->assertCount(1, FallbackRegistry::occurrences());
    }
}
