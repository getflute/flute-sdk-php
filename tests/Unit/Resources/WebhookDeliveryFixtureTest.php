<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Resources;

use Flute\Sdk\Resources\WebhooksResource;
use PHPUnit\Framework\TestCase;

/**
 * Pins Flute's webhook HMAC signing scheme, header shape, and delivery envelope so
 * drift is caught on every PR, offline.
 *
 * Provenance: the delivery envelope and header shape were verified first-party
 * against two live sandbox `transaction.card.captured` deliveries on 2026-06-26,
 * read back from Flute's webhook delivery-log API. The ids in RAW_BODY are
 * placeholders and the signature is recomputed with the fixed test secret below, so
 * the fixture stays self-contained and credential-free. On the same date the SDK's
 * verifySignature() returned true against a genuine Flute-signed delivery, so the
 * "v1,<base64(HMAC-SHA256)>" scheme over "{id}.{timestamp}.{rawBody}" is confirmed
 * end-to-end — not merely self-consistent.
 *
 * The delivery is a thin, fetch-by-id event: the transaction id is at
 * `data.object.id` (with `data.object.resourceType: "transaction"`) and there is no
 * inline status/amount — call getTransaction(id) for detail. Flute's OpenAPI
 * contract does not define this delivery envelope (only the webhook management
 * objects); the shape here is the one confirmed first-party from live deliveries.
 *
 * What this guards: header parsing ("v1,<base64>"), signed-content composition over
 * the exact raw bytes, and the delivery envelope shape. It does not re-prove the
 * production signing scheme on every run — only a live delivery does that
 * (done 2026-06-26).
 */
final class WebhookDeliveryFixtureTest extends TestCase
{
    // Fixed test secret — NOT the live sandbox secret. The signature constant is
    // recomputed from this so the fixture is self-contained and credential-free.
    private const SECRET = 'whsec_fixture_0123456789abcdef';

    // Captured headers (Flute-Webhook-*), sanitized.
    private const HEADER_ID = 'msg_2fW3xQ8kLpR1aBcD';
    private const HEADER_TIMESTAMP = '1749913200';

    // Real delivery envelope (verified first-party 2026-06-26), ids replaced with
    // placeholders. Flute sends spaced JSON; byte-stability matters because
    // re-serializing parsed JSON would change whitespace/key order and break the
    // HMAC — the whole point of pinning. Split with concatenation for the 120-col limit.
    private const RAW_BODY = '{"id": "11111111-1111-4111-8111-111111111111", "data": '
        . '{"object": {"id": "22222222-2222-4222-8222-222222222222", "resourceType": '
        . '"transaction"}}, "type": "transaction.card.captured", "created": 1782507580, '
        . '"apiVersion": "v2"}';

    private function fixtureSignature(): string
    {
        $mac = hash_hmac(
            'sha256',
            self::HEADER_ID . '.' . self::HEADER_TIMESTAMP . '.' . self::RAW_BODY,
            self::SECRET,
            true,
        );

        return 'v1,' . base64_encode($mac);
    }

    public function testCapturedDeliveryVerifies(): void
    {
        // verifySignature (not verify) so the pinned past timestamp is not
        // rejected by the freshness window — this checks the HMAC path only.
        self::assertTrue((new WebhooksResource())->verifySignature(
            $this->fixtureSignature(),
            self::HEADER_ID,
            self::HEADER_TIMESTAMP,
            self::RAW_BODY,
            self::SECRET,
        ));
    }

    public function testSignatureHeaderMatchesDocumentedShape(): void
    {
        // "v1," scheme prefix followed by standard base64 of 32 HMAC-SHA256 bytes.
        $header = $this->fixtureSignature();
        self::assertStringStartsWith('v1,', $header);

        $decoded = base64_decode(substr($header, 3), true);
        self::assertNotFalse($decoded);
        self::assertSame(32, strlen((string) $decoded));
    }

    public function testFixtureBodyMatchesVerifiedEnvelopeShape(): void
    {
        // Locks the first-party-verified envelope (2026-06-26): top-level `type` and
        // `apiVersion`, the transaction id at data.object.id, resourceType
        // "transaction", and a thin event with no inline status/amount.
        /** @var array<string, mixed> $body */
        $body = json_decode(self::RAW_BODY, true);
        self::assertSame('transaction.card.captured', $body['type']);
        self::assertSame('v2', $body['apiVersion']);
        self::assertSame('transaction', $body['data']['object']['resourceType']);
        self::assertArrayHasKey('id', $body['data']['object']);
        self::assertArrayNotHasKey('status', $body['data']);
        self::assertArrayNotHasKey('amount', $body['data']);
    }

    public function testReParsedBodyBreaksVerification(): void
    {
        // Demonstrates why callers must pass raw bytes: re-encoding the decoded
        // JSON shifts whitespace and fails the HMAC even though the data matches.
        $reEncoded = (string) json_encode(json_decode(self::RAW_BODY, true));
        self::assertNotSame(self::RAW_BODY, $reEncoded);

        self::assertFalse((new WebhooksResource())->verifySignature(
            $this->fixtureSignature(),
            self::HEADER_ID,
            self::HEADER_TIMESTAMP,
            $reEncoded,
            self::SECRET,
        ));
    }
}
