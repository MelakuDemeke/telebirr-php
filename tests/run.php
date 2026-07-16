<?php

/**
 * Dependency-free test runner for telebirr-php (no PHPUnit required).
 *
 * Run with:  php tests/run.php
 * Exits non-zero on any failure.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Melaku\Telebirr\Config;
use Melaku\Telebirr\Exceptions\ApiException;
use Melaku\Telebirr\Http\HttpClientException;
use Melaku\Telebirr\Http\HttpClientInterface;
use Melaku\Telebirr\Http\HttpResponse;
use Melaku\Telebirr\KeyNormalizer;
use Melaku\Telebirr\Telebirr;
use Psr\Log\AbstractLogger;

// ---------------------------------------------------------------------------
// Tiny test harness
// ---------------------------------------------------------------------------

$failures = 0;
$passed = 0;

function check(bool $condition, string $label): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  ✓ {$label}\n";
    } else {
        $failures++;
        echo "  ✗ FAIL: {$label}\n";
    }
}

function section(string $name): void
{
    echo "\n{$name}\n";
}

/** HttpClientInterface fake returning canned responses (or throwing canned exceptions). */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, HttpResponse|HttpClientException> */
    private array $responses;
    /** @var array<int, array{url: string, headers: array, body: string}> */
    public array $calls = [];

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function post(string $url, array $headers, string $body): HttpResponse
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->responses);
        if ($next === null) {
            throw new \RuntimeException('FakeHttpClient: no more canned responses for ' . $url);
        }
        if ($next instanceof HttpClientException) {
            throw $next;
        }
        return $next;
    }
}

/** PSR-3 logger that records everything for assertions. */
final class CollectingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    public function messagesAt(string $level): array
    {
        $out = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $out[] = $record['message'];
            }
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

$keyResource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($keyResource, $pemPrivateKey); // PKCS#8 PEM
// Bare base64 DER, as Ethio Telecom issues keys:
$bareBase64Key = preg_replace('/-----[^-]+-----|\s+/', '', $pemPrivateKey);

$publicPem = openssl_pkey_get_details($keyResource)['key']; // SPKI PEM
$barePublicKey = preg_replace('/-----[^-]+-----|\s+/', '', $publicPem);

function baseOptions(string $privateKey): array
{
    return [
        'fabricAppId'   => 'fabric-app-id',
        'appSecret'     => 'secret',
        'merchantAppId' => 'merchant-app-id',
        'merchantCode'  => '123456',
        'privateKey'    => $privateKey,
        'notifyUrl'     => 'https://example.com/notify',
    ];
}

$southboundError = json_encode([
    'errorCode'     => '49401024991',
    'errorMsg'      => 'When the engine tries to call a southbound business service, it finds that the service is unavailable.',
    'errorSolution' => 'Wait and retry.',
]);

function tokenResponse(int $expiresInSeconds = 3600): HttpResponse
{
    return new HttpResponse(200, json_encode([
        'token'          => 'Bearer abc',
        'expirationDate' => (string) ((time() + $expiresInSeconds) * 1000),
    ]));
}

function queryResponse(): HttpResponse
{
    return new HttpResponse(200, json_encode([
        'code'        => '00000',
        'biz_content' => [
            'trade_status'     => 'PAY_SUCCESS',
            'total_amount'     => '100.00',
            'trans_currency'   => 'ETB',
            'payment_order_id' => 'TB123',
            'merch_order_id'   => 'ORDER123',
            'trans_end_time'   => '1700000000000',
        ],
    ]));
}

// ---------------------------------------------------------------------------
// KeyNormalizer + Config normalization
// ---------------------------------------------------------------------------

section('KeyNormalizer');
$normalized = KeyNormalizer::normalizePrivateKey($bareBase64Key);
check(strpos($normalized, '-----BEGIN PRIVATE KEY-----') !== false, 'bare base64 private key gets PEM armor');
check(@openssl_pkey_get_private($normalized) !== false, 'normalized private key parses with OpenSSL');
check(KeyNormalizer::normalizePrivateKey($pemPrivateKey) === trim($pemPrivateKey), 'existing PEM passes through');
$flattened = str_replace("\n", '\n', trim($pemPrivateKey));
check(@openssl_pkey_get_private(KeyNormalizer::normalizePrivateKey($flattened)) !== false, 'literal \n PEM is repaired');
check(KeyNormalizer::normalizePrivateKey('not a key!!') === 'not a key!!', 'garbage left untouched');
$normalizedPub = KeyNormalizer::normalizePublicKey($barePublicKey);
check(@openssl_pkey_get_public($normalizedPub) !== false, 'bare base64 public key normalizes and parses');

section('Config key normalization + validate');
$config = Config::forTest(baseOptions($bareBase64Key));
check(strpos($config->privateKey, '-----BEGIN PRIVATE KEY-----') !== false, 'Config normalizes bare base64 privateKey');
check($config->validate() === true, 'validate() passes for a normalized key');
$configPub = Config::forTest(baseOptions($bareBase64Key) + ['telebirrPublicKey' => $barePublicKey]);
check(strpos((string) $configPub->telebirrPublicKey, '-----BEGIN PUBLIC KEY-----') !== false, 'Config normalizes telebirrPublicKey');

// ---------------------------------------------------------------------------
// ApiException structured fields + isTransient
// ---------------------------------------------------------------------------

section('ApiException structured fields');
$e = new ApiException('boom', 500, null, $southboundError);
check($e->getTelebirrCode() === '49401024991', 'telebirrCode parsed from body');
check(strpos((string) $e->getTelebirrMessage(), 'southbound') !== false, 'telebirrMessage parsed');
check($e->getTelebirrSolution() === 'Wait and retry.', 'telebirrSolution parsed');
check($e->getErrorCode() === '49401024991', 'errorCode backfilled from envelope');
check($e->isTransient() === true, '49401024991 is transient');
$success = new ApiException('x', 200, null, json_encode(['code' => '00000']));
check($success->getTelebirrCode() === null, 'success code 00000 is not an error envelope');
check((new ApiException('x', 503))->isTransient() === true, 'HTTP 503 is transient');
check((new ApiException('x', 400))->isTransient() === false, 'HTTP 400 is not transient');
$curlTimeout = new HttpClientException('timed out', 28);
check((new ApiException('x', null, null, null, 28, $curlTimeout))->isTransient() === true, 'cURL 28 timeout is transient');

// ---------------------------------------------------------------------------
// Retry
// ---------------------------------------------------------------------------

section('Opt-in retry');
$http = new FakeHttpClient([new HttpResponse(500, $southboundError), tokenResponse()]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http, ['retry' => ['retries' => 2, 'delayMs' => 1]]);
$result = $client->applyFabricToken();
check($result['token'] === 'Bearer abc' && count($http->calls) === 2, 'transient error retried, then succeeds');

$http = new FakeHttpClient([new HttpResponse(500, $southboundError)]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http);
try {
    $client->applyFabricToken();
    check(false, 'no retry without opt-in (should have thrown)');
} catch (ApiException $ex) {
    check(count($http->calls) === 1, 'no retry without opt-in');
}

$http = new FakeHttpClient([new HttpResponse(400, json_encode(['errorCode' => '49401024995', 'errorMsg' => 'bad param']))]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http, ['retry' => ['retries' => 3, 'delayMs' => 1]]);
try {
    $client->applyFabricToken();
    check(false, 'non-transient error not retried (should have thrown)');
} catch (ApiException $ex) {
    check(count($http->calls) === 1, 'non-transient error not retried');
}

// ---------------------------------------------------------------------------
// Token cache + getOrderStatus
// ---------------------------------------------------------------------------

section('Fabric token caching');
$http = new FakeHttpClient([tokenResponse(), queryResponse(), queryResponse()]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http);
$client->getOrderStatus('ORDER123');
$client->getOrderStatus('ORDER123');
check(count($http->calls) === 3, 'token fetched once for two getOrderStatus calls (1 token + 2 query)');
check(strpos($http->calls[0]['url'], '/payment/v1/token') !== false, 'first call is the token endpoint');

$http = new FakeHttpClient([tokenResponse(30), queryResponse(), tokenResponse(), queryResponse()]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http);
$client->getOrderStatus('ORDER123');
$client->getOrderStatus('ORDER123');
check(count($http->calls) === 4, 'token expiring within safety margin is refreshed');

$http = new FakeHttpClient([tokenResponse(), queryResponse(), tokenResponse(), queryResponse()]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http, ['cacheFabricToken' => false]);
$client->getOrderStatus('ORDER123');
$client->getOrderStatus('ORDER123');
check(count($http->calls) === 4, 'cacheFabricToken=false disables the cache');

section('getOrderStatus');
$http = new FakeHttpClient([tokenResponse(), queryResponse()]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http);
$status = $client->getOrderStatus('ORDER123');
check($status->paid === true && $status->failed === false && $status->cancelled === false, 'PAY_SUCCESS maps to paid');
check($status->amount === '100.00' && $status->currency === 'ETB', 'amount/currency mapped');
check($status->paymentOrderId === 'TB123' && $status->merchOrderId === 'ORDER123', 'ids mapped');
check(($status->raw['code'] ?? null) === '00000', 'raw response retained');

$http = new FakeHttpClient([tokenResponse(), new HttpResponse(200, json_encode([
    'code' => '00000',
    'biz_content' => ['tradeStatus' => 'PAY_FAILED', 'totalAmount' => '10.00'],
]))]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http);
$status = $client->getOrderStatus('ORDER123');
check($status->failed === true && $status->amount === '10.00', 'camelCase variants handled');
check($status->merchOrderId === 'ORDER123', 'merchOrderId falls back to the requested id');

$http = new FakeHttpClient([tokenResponse(), new HttpResponse(200, json_encode(['code' => '00000', 'biz_content' => []]))]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http);
check($client->getOrderStatus('ORDER123')->paid === false, 'missing trade_status fails closed');

// ---------------------------------------------------------------------------
// ping
// ---------------------------------------------------------------------------

section('ping');
$http = new FakeHttpClient([tokenResponse()]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http);
$health = $client->ping();
check($health['ok'] === true && $health['error'] === null, 'ping ok on healthy gateway');

$http = new FakeHttpClient([new HttpResponse(500, $southboundError)]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http);
$health = $client->ping();
check($health['ok'] === false && strpos((string) $health['error'], '49401024991') !== false, 'ping reports failure without throwing');

// ---------------------------------------------------------------------------
// Construction warnings
// ---------------------------------------------------------------------------

section('Construction warnings');
$logger = new CollectingLogger();
new Telebirr(Config::forTest(array_merge(baseOptions($pemPrivateKey), ['notifyUrl' => 'http://localhost:3000/notify'])), $logger, new FakeHttpClient([]));
check(count(preg_grep('/cannot reach it/', $logger->messagesAt('warning'))) === 1, 'localhost notifyUrl warns');

$logger = new CollectingLogger();
new Telebirr(Config::forTest(array_merge(baseOptions($pemPrivateKey), ['verifySsl' => false])), $logger, new FakeHttpClient([]));
check(count(preg_grep('/TLS verification is disabled/', $logger->messagesAt('warning'))) === 1, 'verifySsl=false warns on test');

$logger = new CollectingLogger();
new Telebirr(Config::forProduction(array_merge(baseOptions($pemPrivateKey), ['verifySsl' => false])), $logger, new FakeHttpClient([]));
check(count(preg_grep('/PRODUCTION/', $logger->messagesAt('error'))) === 1, 'verifySsl=false errors on production');

$logger = new CollectingLogger();
new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), $logger, new FakeHttpClient([]));
check(count($logger->records) === 0, 'clean config stays silent');

// ---------------------------------------------------------------------------
// fromEnvironment credentials
// ---------------------------------------------------------------------------

section('Config::fromEnvironment env credentials');
$_ENV['TELEBIRR_ENVIRONMENT'] = 'test';
$_ENV['TELEBIRR_FABRIC_APP_ID'] = 'env-fabric';
$_ENV['TELEBIRR_APP_SECRET'] = 'env-secret';
$_ENV['TELEBIRR_MERCHANT_APP_ID'] = 'env-merchant';
$_ENV['TELEBIRR_MERCHANT_CODE'] = '654321';
$_ENV['TELEBIRR_PRIVATE_KEY'] = $bareBase64Key;
$_ENV['TELEBIRR_NOTIFY_URL'] = 'https://example.com/env-notify';
$config = Config::fromEnvironment();
check($config->fabricAppId === 'env-fabric' && $config->merchantCode === '654321', 'credentials read from env vars');
check($config->notifyUrl === 'https://example.com/env-notify', 'notifyUrl read from env');
check($config->validate() === true, 'env-sourced config validates (key normalized)');
$config = Config::fromEnvironment(['fabricAppId' => 'explicit']);
check($config->fabricAppId === 'explicit', 'explicit option overrides env var');
foreach (['TELEBIRR_ENVIRONMENT', 'TELEBIRR_FABRIC_APP_ID', 'TELEBIRR_APP_SECRET', 'TELEBIRR_MERCHANT_APP_ID', 'TELEBIRR_MERCHANT_CODE', 'TELEBIRR_PRIVATE_KEY', 'TELEBIRR_NOTIFY_URL'] as $var) {
    unset($_ENV[$var]);
}

// ---------------------------------------------------------------------------
// Backward compatibility smoke: createCheckoutUrl full flow
// ---------------------------------------------------------------------------

section('createCheckoutUrl (full flow, backward compat)');
$http = new FakeHttpClient([
    tokenResponse(),
    new HttpResponse(200, json_encode(['code' => '00000', 'biz_content' => ['prepay_id' => 'PID123']])),
]);
$client = new Telebirr(Config::forTest(baseOptions($pemPrivateKey)), null, $http);
$checkout = $client->createCheckoutUrl('Order 123', '100.00', 'ORDER123');
check($checkout->getMerchOrderId() === 'ORDER123', 'exact merchOrderId returned');
check(strpos($checkout->getCheckoutUrl(), 'prepay_id=PID123') !== false, 'checkout URL contains prepay_id');

// ---------------------------------------------------------------------------

echo "\n————————————————\n{$passed} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
