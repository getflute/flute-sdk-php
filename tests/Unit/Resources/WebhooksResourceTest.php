<?php

declare(strict_types=1);

namespace Flute\Sdk\Tests\Unit\Resources;

use Flute\Sdk\Exceptions\FluteWebhookException;
use Flute\Sdk\Resources\WebhooksResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhooksResourceTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';
    private const WEBHOOK_ID = 'wh-evt-1';

    private WebhooksResource $webhooks;

    protected function setUp(): void
    {
        $this->webhooks = new WebhooksResource();
    }

    private static function sign(string $id, string $timestamp, string $body, string $secret): string
    {
        $mac = hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $secret, true);

        return 'v1,' . base64_encode($mac);
    }

    public function testValidSignatureReturnsTrue(): void
    {
        $body = '{"event":"transaction.approved","amount":100}';
        $timestamp = (string) time();
        $signature = self::sign(self::WEBHOOK_ID, $timestamp, $body, self::SECRET);

        self::assertTrue($this->webhooks->verifySignature(
            $signature,
            self::WEBHOOK_ID,
            $timestamp,
            $body,
            self::SECRET,
        ));
    }

    public function testTamperedBodyReturnsFalse(): void
    {
        $timestamp = (string) time();
        $signature = self::sign(self::WEBHOOK_ID, $timestamp, '{"amount":100}', self::SECRET);

        self::assertFalse($this->webhooks->verifySignature(
            $signature,
            self::WEBHOOK_ID,
            $timestamp,
            '{"amount":999}', // modified after signing
            self::SECRET,
        ));
    }

    public function testWrongSecretReturnsFalse(): void
    {
        $body = '{"a":1}';
        $timestamp = (string) time();
        $signature = self::sign(self::WEBHOOK_ID, $timestamp, $body, 'other-secret');

        self::assertFalse($this->webhooks->verifySignature(
            $signature,
            self::WEBHOOK_ID,
            $timestamp,
            $body,
            self::SECRET,
        ));
    }

    public function testUnknownSchemeReturnsFalse(): void
    {
        self::assertFalse($this->webhooks->verifySignature(
            'v2,AAAA',
            self::WEBHOOK_ID,
            (string) time(),
            '{}',
            self::SECRET,
        ));
    }

    public function testInvalidBase64ReturnsFalse(): void
    {
        self::assertFalse($this->webhooks->verifySignature(
            'v1,not-base64!!!',
            self::WEBHOOK_ID,
            (string) time(),
            '{}',
            self::SECRET,
        ));
    }

    public function testMissingSchemeSeparatorReturnsFalse(): void
    {
        self::assertFalse($this->webhooks->verifySignature(
            'AAAA',
            self::WEBHOOK_ID,
            (string) time(),
            '{}',
            self::SECRET,
        ));
    }

    #[DataProvider('malformedSignatureHeaderProvider')]
    public function testMalformedSignatureHeaderReturnsFalse(string $signature): void
    {
        self::assertFalse($this->webhooks->verifySignature(
            $signature,
            self::WEBHOOK_ID,
            (string) time(),
            '{}',
            self::SECRET,
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedSignatureHeaderProvider(): iterable
    {
        yield 'separator at position 0' => [',AAAA'];
        yield 'empty encoded part' => ['v1,'];
    }

    public function testTruncatedSignatureReturnsFalse(): void
    {
        $body = '{"a":1}';
        $timestamp = (string) time();
        $mac = hash_hmac('sha256', self::WEBHOOK_ID . '.' . $timestamp . '.' . $body, self::SECRET, true);

        self::assertFalse($this->webhooks->verifySignature(
            'v1,' . base64_encode(substr($mac, 0, 8)), // valid base64, hash_equals length mismatch
            self::WEBHOOK_ID,
            $timestamp,
            $body,
            self::SECRET,
        ));
    }

    public function testWhitespaceInSignatureIsSkippedByStrictBase64(): void
    {
        $body = '{"a":1}';
        $timestamp = (string) time();
        $mac = hash_hmac('sha256', self::WEBHOOK_ID . '.' . $timestamp . '.' . $body, self::SECRET, true);

        // Deliberate leniency: strict base64_decode skips whitespace; the
        // decoded bytes must still match the HMAC, so this is harmless.
        self::assertTrue($this->webhooks->verifySignature(
            'v1, ' . base64_encode($mac),
            self::WEBHOOK_ID,
            $timestamp,
            $body,
            self::SECRET,
        ));
    }

    #[DataProvider('emptyParameterProvider')]
    public function testEmptyParameterThrowsWebhookException(
        string $signature,
        string $id,
        string $timestamp,
        string $body,
        string $secret,
    ): void {
        $this->expectException(FluteWebhookException::class);
        $this->webhooks->verifySignature($signature, $id, $timestamp, $body, $secret);
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function emptyParameterProvider(): iterable
    {
        yield 'empty signature header' => ['', 'id', '123', '{}', 's'];
        yield 'empty id header' => ['v1,AAAA', '', '123', '{}', 's'];
        yield 'empty timestamp header' => ['v1,AAAA', 'id', '', '{}', 's'];
        yield 'empty body' => ['v1,AAAA', 'id', '123', '', 's'];
        yield 'empty secret' => ['v1,AAAA', 'id', '123', '{}', ''];
    }

    public function testIsTimestampFresh(): void
    {
        self::assertTrue($this->webhooks->isTimestampFresh((string) time()));
        self::assertTrue($this->webhooks->isTimestampFresh((string) (time() - 200)));
        self::assertFalse($this->webhooks->isTimestampFresh((string) (time() - 400)));
        self::assertFalse($this->webhooks->isTimestampFresh((string) (time() + 400)));
        self::assertTrue($this->webhooks->isTimestampFresh((string) (time() - 400), 500));
        self::assertFalse($this->webhooks->isTimestampFresh('not-a-number'));
    }

    public function testVerifyAcceptsValidAndFresh(): void
    {
        $body = '{"event":"transaction.settled"}';
        $timestamp = (string) time();
        $signature = self::sign(self::WEBHOOK_ID, $timestamp, $body, self::SECRET);

        self::assertTrue($this->webhooks->verify(
            $signature,
            self::WEBHOOK_ID,
            $timestamp,
            $body,
            self::SECRET,
        ));
    }

    public function testVerifyRejectsValidButStaleSignature(): void
    {
        // A correctly signed but old delivery: signature passes, freshness fails,
        // so the replay-safe verify() rejects it where verifySignature() alone
        // would accept it.
        $body = '{"event":"transaction.settled"}';
        $timestamp = (string) (time() - 3600);
        $signature = self::sign(self::WEBHOOK_ID, $timestamp, $body, self::SECRET);

        self::assertTrue($this->webhooks->verifySignature(
            $signature,
            self::WEBHOOK_ID,
            $timestamp,
            $body,
            self::SECRET,
        ));
        self::assertFalse($this->webhooks->verify(
            $signature,
            self::WEBHOOK_ID,
            $timestamp,
            $body,
            self::SECRET,
        ));
        // A wider tolerance lets the same delivery through.
        self::assertTrue($this->webhooks->verify(
            $signature,
            self::WEBHOOK_ID,
            $timestamp,
            $body,
            self::SECRET,
            7200,
        ));
    }

    public function testVerifyRejectsTamperedBody(): void
    {
        $timestamp = (string) time();
        $signature = self::sign(self::WEBHOOK_ID, $timestamp, '{"amount":100}', self::SECRET);

        self::assertFalse($this->webhooks->verify(
            $signature,
            self::WEBHOOK_ID,
            $timestamp,
            '{"amount":999}',
            self::SECRET,
        ));
    }
}
