<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Hostile;

use PHPUnit\Framework\TestCase;
use Univapay\Compat\Tests\Hostile\Support\FakeServer;
use Univapay\Compat\Tests\Support\FakeJwtBuilder;
use Univapay\Compat\UnivapayClient;
use Univapay\Compat\UnivapayClientOptions;

/**
 * Shared base for tests/Hostile/ -- offline (no docs-repo/Prism dependency at all: unlike
 * tests/Integration/, these tests never skip), backed by a real local HTTP server
 * (tests/Hostile/Support/FakeServer.php) instead of a fake in-process transport, so the FULL
 * stack (Support\Bridge's generated client, real HTTP, Support\ApiCaller) is exercised against
 * response shapes Prism's own spec-driven mock could never be made to serve. See
 * docs/ARCHITECTURE.md for why the raw-hydration path this exercises exists at all.
 */
abstract class HostileTestCase extends TestCase
{
    use FakeJwtBuilder;

    public const STORE_ID = '11edf541-c42d-653c-8c3d-dfe0a55f95c0';
    public const MERCHANT_ID = '01234567-89ab-cdef-0123-456789abcdef';
    public const CHARGE_ID = '11ef0000-0000-4000-8000-000000000001';

    /** @var FakeServer */
    private $server;

    protected function setUp(): void
    {
        $this->server = new FakeServer();
        $this->server->start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    protected function server(): FakeServer
    {
        return $this->server;
    }

    protected function storeClient(): UnivapayClient
    {
        return new UnivapayClient(
            $this->buildStoreAppToken(self::STORE_ID, self::MERCHANT_ID),
            new UnivapayClientOptions($this->server->url())
        );
    }
}
