<?php

declare(strict_types=1);

namespace Univapay\Compat\Tests\Hostile\Support;

use RuntimeException;

/**
 * A real, local HTTP server (PHP's built-in `php -S`, driven by tests/Hostile/fixtures/router.php)
 * used to exercise the compat layer's FULL transport stack -- `Support\Bridge`'s generated
 * `UnivaPay\UnivapayClientSdkClient`, real `apimatic/unirest-php` HTTP, `Support\ApiCaller` -- end
 * to end against response shapes Prism's own spec-driven mock would never serve (Prism validates
 * responses against the spec it was given; the whole point of tests/Hostile/ is shapes the spec
 * does NOT describe, or the old wire format's own quirks -- see docs/ARCHITECTURE.md).
 *
 * Not a mock at the PHP-object level (unlike e.g. tests/Unit/Support/ApiCallerTest.php's synthetic
 * closures) -- a REAL socket, so idempotency headers, connection-refused, and raw response bytes
 * are all genuine transport-level behavior, not something this test suite asserts about its own
 * stand-in.
 */
final class FakeServer
{
    /** @var resource|null */
    private $process;

    /** @var int */
    private $port;

    /** @var string */
    private $configFile;

    /** @var string */
    private $logFile;

    public function __construct()
    {
        $this->port = self::findFreePort();
        $this->configFile = tempnam(sys_get_temp_dir(), 'univapay-hostile-config-');
        $this->logFile = tempnam(sys_get_temp_dir(), 'univapay-hostile-log-');
        file_put_contents($this->configFile, json_encode(['responses' => []]));
        file_put_contents($this->logFile, '');
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Could not find a free port: $errstr ($errno)");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * @param array $responses List of ['status' => int, 'headers' => array, 'body' => string].
     *        The Nth incoming request gets responses[N-1]; once exhausted, every later request
     *        repeats the last one (see router.php's own doc).
     */
    public function queueResponses(array $responses): void
    {
        file_put_contents($this->configFile, json_encode(['responses' => $responses]));
    }

    public function queueResponse(int $status, string $body, array $headers = []): void
    {
        $this->queueResponses([['status' => $status, 'headers' => $headers, 'body' => $body]]);
    }

    public function start(): void
    {
        $router = __DIR__ . '/../fixtures/router.php';
        $env = array_merge($_ENV, [
            'HOSTILE_FAKE_SERVER_CONFIG' => $this->configFile,
            'HOSTILE_FAKE_SERVER_LOG' => $this->logFile,
        ]);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        // proc_open()'s array-command form needs PHP 7.4+ (PHPCompatibility flags it against
        // this package's 7.2 floor) -- build the command as an escaped STRING instead.
        $command = escapeshellarg($phpBinary) . ' -S ' . escapeshellarg("127.0.0.1:{$this->port}")
            . ' ' . escapeshellarg($router);
        $this->process = proc_open(
            $command,
            $descriptors,
            $pipes,
            null,
            $env
        );
        if (!is_resource($this->process)) {
            throw new RuntimeException('Failed to start the fake HTTP server (proc_open failed)');
        }
        // Don't let the child's stdout/stderr pipes fill up and block it -- we never read them.
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(50000);
        }
        $this->stop();
        throw new RuntimeException("Fake HTTP server never became reachable on port {$this->port}");
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if (is_file($this->configFile)) {
            unlink($this->configFile);
        }
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    /**
     * @return array[] Every request received so far, in order: each
     *         ['method' => string, 'uri' => string, 'headers' => array<string,string> (lowercase
     *         keys), 'body' => string].
     */
    public function capturedRequests(): array
    {
        $contents = file_get_contents($this->logFile);
        $lines = array_filter(explode("\n", $contents), function ($line) {
            return trim($line) !== '';
        });
        return array_map(function ($line) {
            return json_decode($line, true);
        }, $lines);
    }
}
