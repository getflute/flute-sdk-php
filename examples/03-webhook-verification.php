<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Flute\Sdk\Flute;

$flute = new Flute([
    'clientId' => (string) getenv('FLUTE_CLIENT_ID'),
    'clientSecret' => (string) getenv('FLUTE_CLIENT_SECRET'),
    'environment' => 'sandbox',
]);

// Simulated delivery signed the way Flute signs: HMAC-SHA256 over "id.timestamp.body".
// The body is OPAQUE to verification — the HMAC covers the exact raw bytes; the SDK
// never parses it. The shape below mirrors Flute's real delivery envelope (verified
// first-party 2026-06-26): a thin event whose transaction id is at data.object.id —
// call $flute->transactions->getTransaction(id) for detail. See docs/open-questions.md.
$secret = (string) (getenv('FLUTE_WEBHOOK_SECRET') ?: 'whsec_example_secret');
$webhookId = 'evt-0001';
$timestamp = (string) time();
$rawBody = '{"id":"evt-0001","type":"transaction.card.captured","created":1782507580,'
    . '"apiVersion":"v2","data":{"object":{"id":"txn-123","resourceType":"transaction"}}}';
$signature = 'v1,' . base64_encode(
    hash_hmac('sha256', $webhookId . '.' . $timestamp . '.' . $rawBody, $secret, true),
);

/*
 * verify() checks the HMAC signature and timestamp freshness together, so a
 * replayed (stale) delivery is rejected by default.
 */
$genuine = $flute->webhooks->verify($signature, $webhookId, $timestamp, $rawBody, $secret);
/*
 * In production also dedupe on $webhookId: persist it with a TTL >= the freshness
 * window and drop a delivery whose ID you have already processed (replays verify
 * within the window). See README "Webhook signature verification".
 */
echo 'Genuine payload: ' . var_export($genuine, true) . PHP_EOL;

/*
 * A tampered body fails the HMAC on its own, so this negative case checks the
 * signature alone (verifySignature) — freshness is irrelevant to it. Prefer
 * verify() in real handlers, where replay protection must stay on by default.
 */
$tampered = $flute->webhooks->verifySignature(
    $signature,
    $webhookId,
    $timestamp,
    '{"id":"evt-0001","type":"transaction.card.captured","created":1782507580,'
        . '"apiVersion":"v2","data":{"object":{"id":"txn-999","resourceType":"transaction"}}}',
    $secret,
);
echo 'Tampered payload: ' . var_export($tampered, true) . PHP_EOL;
