<?php

/**
 * Router script for PHP's built-in web server (`php -S 127.0.0.1:<port> router.php`), driven by
 * Univapay\Compat\Tests\Hostile\Support\FakeServer. Not a PHPUnit test itself.
 *
 * Protocol (see FakeServer's own class doc for the full rationale): a real local HTTP server is
 * needed so tests/Hostile/ exercises the FULL transport stack (Support\Bridge's generated
 * UnivaPay\UnivapayClientSdkClient -> apimatic/unirest-php -> real HTTP -> Support\ApiCaller) end
 * to end against response shapes Prism's own spec-driven validation would refuse to ever
 * serve -- deliberately spec-invalid, or old-wire-format-quirk, 2xx/4xx bodies.
 *
 * - HOSTILE_FAKE_SERVER_CONFIG env var: path to a JSON file `{"responses": [{"status":int,
 *   "headers":{k:v}, "body":string}, ...]}`. Read FRESH on every request (so a test can queue
 *   more responses mid-run for a multi-attempt scenario, though most tests configure the whole
 *   sequence upfront).
 * - HOSTILE_FAKE_SERVER_LOG env var: path to append one JSON line per incoming request
 *   (`{"method":..., "headers":{...}, "body":...}`) BEFORE responding, so FakeServer::
 *   capturedRequests() can assert on what was actually sent (e.g. the Idempotency-Key header
 *   across retries).
 *
 * Response selection: the Nth incoming request (1-indexed, by log line count after appending)
 * gets `responses[N-1]`; once the queue is exhausted, every subsequent request repeats the LAST
 * queued response (so a single-response queue is enough for any test that only expects one call).
 */

$configPath = getenv('HOSTILE_FAKE_SERVER_CONFIG');
$logPath = getenv('HOSTILE_FAKE_SERVER_LOG');

$headers = [];
if (function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        $headers[strtolower($name)] = $value;
    }
} else {
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
    }
}

$requestBody = file_get_contents('php://input');

$logLine = json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => $headers,
    'body' => $requestBody,
]) . "\n";

// NOTE: 'a' (append) mode is WRITE-ONLY in PHP -- rewind()+fgets() on that same handle silently
// fails every read (errno=9 "Bad file descriptor") and feof() never becomes true, which used to
// spin fgets() forever, printing an endless stream of PHP notices as the "response body" until
// the CLIENT (curl, inside apimatic/unirest-php) exhausted its memory limit trying to buffer it.
// Fix: write with one handle, then close it and open a SEPARATE read-only handle to count lines.
$fh = fopen($logPath, 'a');
flock($fh, LOCK_EX);
fwrite($fh, $logLine);
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

$requestNumber = count(file($logPath, FILE_SKIP_EMPTY_LINES));

$config = json_decode(file_get_contents($configPath), true);
$responses = $config['responses'] ?? [];
$index = min($requestNumber, count($responses)) - 1;
$index = max($index, 0);
$response = $responses[$index] ?? ['status' => 500, 'headers' => [], 'body' => '{}'];

http_response_code($response['status']);
header('Content-Type: application/json');
foreach (($response['headers'] ?? []) as $name => $value) {
    header("$name: $value");
}
echo $response['body'];
