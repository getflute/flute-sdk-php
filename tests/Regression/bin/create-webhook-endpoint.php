<?php

declare(strict_types=1);

// Idempotent setup: deletes any prior regression endpoints (webhookName
// prefixed REGRESSION_PREFIX) before creating a fresh one, so repeated runs do
// not leak sandbox endpoints. The hmacSecret is only returned at creation, so a
// fresh endpoint is created each run rather than reusing an old one.
require __DIR__ . '/../../../vendor/autoload.php';

const REGRESSION_PREFIX = 'sdk-regression';

use Flute\Sdk\Enums\Environment;
use Flute\Sdk\Flute;
use GuzzleHttp\Client;

$clientId = getenv('FLUTE_CLIENT_ID');
$clientSecret = getenv('FLUTE_CLIENT_SECRET');
if ($clientId === false || $clientId === '' || $clientSecret === false || $clientSecret === '') {
    fwrite(STDERR, "Set FLUTE_CLIENT_ID and FLUTE_CLIENT_SECRET first.\n");
    exit(1);
}

$flute = new Flute([
    'clientId' => $clientId,
    'clientSecret' => $clientSecret,
    'environment' => 'sandbox',
]);
$token = $flute->sessions->getAccessToken();

$endpointUrl = getenv('FLUTE_WEBHOOK_ENDPOINT_URL') ?: 'https://example.com/flute-sdk-regression';

// Honour FLUTE_API_BASE_URL override (mirrors LiveTestCase pattern).
$apiBase = getenv('FLUTE_API_BASE_URL') ?: Environment::SANDBOX->apiBaseUrl();

// Bounded timeouts so a network hang cannot block the helper indefinitely.
$http = new Client([
    'timeout' => 30,
    'connect_timeout' => 10,
]);

$authHeader = ['Authorization' => 'Bearer ' . $token];

// Idempotence: delete any leftover regression endpoints from prior runs.
try {
    $existingRaw = json_decode(
        (string) $http->get($apiBase . '/v2/webhooks/endpoints', ['headers' => $authHeader])->getBody(),
        true,
    );
    $existing = is_array($existingRaw) && is_array($existingRaw['data'] ?? null) ? $existingRaw['data'] : [];
    foreach ($existing as $endpoint) {
        if (!is_array($endpoint)) {
            continue;
        }
        $name = $endpoint['webhookName'] ?? null;
        $id = $endpoint['endpointId'] ?? null;
        if (is_string($name) && is_string($id) && str_starts_with($name, REGRESSION_PREFIX)) {
            $http->delete($apiBase . '/v2/webhooks/endpoints/' . rawurlencode($id), ['headers' => $authHeader]);
            fwrite(STDERR, "Removed leftover regression endpoint: $id\n");
        }
    }
} catch (\GuzzleHttp\Exception\GuzzleException $e) {
    // Cleanup is best-effort; a failure here should not abort setup.
    fwrite(STDERR, 'Endpoint cleanup skipped: ' . $e->getMessage() . "\n");
}

// Response shape: {"data": [{"eventTypeId": 13, "name": "transaction.ach.cancelled", ...}]}
try {
    $eventTypesRaw = json_decode(
        (string) $http->get(
            $apiBase . '/v2/webhooks/event-types',
            ['headers' => $authHeader],
        )->getBody(),
        true,
    );
} catch (\GuzzleHttp\Exception\GuzzleException $e) {
    fwrite(STDERR, 'Webhook API call failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (
    !is_array($eventTypesRaw)
    || !isset($eventTypesRaw['data'])
    || !is_array($eventTypesRaw['data'])
    || $eventTypesRaw['data'] === []
) {
    fwrite(STDERR, "Could not list webhook event types.\n");
    exit(1);
}

// Subscribe to the first available event type; the secret is what matters here.
$first = $eventTypesRaw['data'][0];
if (!is_array($first) || !isset($first['name']) || !is_string($first['name'])) {
    fwrite(STDERR, "Unexpected event type shape.\n");
    exit(1);
}

// Real wire field is "webhookName" (not "name" as in OpenAPI spec).
// Real response: endpointId, webhookName, endpointUrl, status, hmacSecret, eventTypes, createdAt.
try {
    $responseRaw = json_decode(
        (string) $http->post(
            $apiBase . '/v2/webhooks/endpoints',
            [
                'headers' => $authHeader,
                'json' => [
                    'webhookName' => REGRESSION_PREFIX . '-' . date('Ymd-His'),
                    'endpointUrl' => $endpointUrl,
                    'eventTypes' => [$first['name']],
                ],
            ],
        )->getBody(),
        true,
    );
} catch (\GuzzleHttp\Exception\GuzzleException $e) {
    fwrite(STDERR, 'Webhook API call failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!is_array($responseRaw) || !isset($responseRaw['hmacSecret']) || !is_string($responseRaw['hmacSecret'])) {
    fwrite(STDERR, "Endpoint created but no hmacSecret returned. Inspect response:\n");
    fwrite(STDERR, json_encode($responseRaw, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

$endpointId = isset($responseRaw['endpointId']) && is_string($responseRaw['endpointId'])
    ? $responseRaw['endpointId']
    : 'unknown';
if ($endpointId === 'unknown') {
    fwrite(STDERR, "Warning: endpointId missing from response; endpoint was still created.\n");
}
echo "Webhook endpoint id: " . $endpointId . "\n";
// hmacSecret is hex — no shell-quoting hazard.
echo "export FLUTE_WEBHOOK_SECRET='" . $responseRaw['hmacSecret'] . "'\n";
