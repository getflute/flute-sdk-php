<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Regression;

use Flute\Sdk\Exceptions\FluteWebhookException;
use Flute\Sdk\Resources\WebhooksResource;

/**
 * Webhook signature scenarios (H-15, H-16, H-17), exercised against the HMAC
 * secret minted live for the sandbox endpoint. The payload is signed locally
 * exactly as Flute signs deliveries: HMAC-SHA256 over "{id}.{timestamp}.{body}",
 * header "v1,<base64>". No API credentials are needed.
 */
final class WebhooksRegressionTest extends RegressionTestCase
{
    /** @testdox H-15: a validly signed payload verifies true */
    public function testValidSignatureVerifies(): void
    {
        $secret = $this->secret();
        [$id, $timestamp, $body, $signature] = $this->signedPayload($secret);

        self::assertTrue(
            (new WebhooksResource())->verifySignature($signature, $id, $timestamp, $body, $secret),
        );
    }

    /** @testdox H-16: a tampered body verifies false without throwing */
    public function testTamperedBodyVerifiesFalse(): void
    {
        $secret = $this->secret();
        [$id, $timestamp, $body, $signature] = $this->signedPayload($secret);
        $tampered = substr($body, 0, -1) . ',"injected":true}';

        self::assertFalse(
            (new WebhooksResource())->verifySignature($signature, $id, $timestamp, $tampered, $secret),
        );
    }

    /** @testdox H-17: an empty signature header raises FluteWebhookException */
    public function testEmptySignatureHeaderThrows(): void
    {
        $secret = $this->secret();
        [$id, $timestamp, $body] = $this->signedPayload($secret);

        $this->expectException(FluteWebhookException::class);
        (new WebhooksResource())->verifySignature('', $id, $timestamp, $body, $secret);
    }

    private function secret(): string
    {
        $secret = self::env('FLUTE_WEBHOOK_SECRET');
        if ($secret === null) {
            self::markTestSkipped(
                'Set FLUTE_WEBHOOK_SECRET to run webhook scenarios. Mint one with '
                . 'tests/Regression/bin/create-webhook-endpoint.php.',
            );
        }

        return $secret;
    }

    /** @return array{string, string, string, string} id, timestamp, body, signature header */
    private function signedPayload(string $secret): array
    {
        $id = 'msg_' . bin2hex(random_bytes(8));
        $timestamp = (string) time();
        $body = '{"eventType":"transaction.ach.cancelled","data":{"regression":"h15-h17"}}';
        $signature = 'v1,' . base64_encode(hash_hmac('sha256', "$id.$timestamp.$body", $secret, true));

        return [$id, $timestamp, $body, $signature];
    }
}
