<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Tests\Unit\Webhook;

use GoSuccess\Wordfence\Exception\InvalidSignatureException;
use GoSuccess\Wordfence\Exception\SerializationException;
use GoSuccess\Wordfence\Webhook\Notification;
use GoSuccess\Wordfence\Webhook\Webhook;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Webhook::class)]
#[CoversClass(Notification::class)]
#[CoversClass(InvalidSignatureException::class)]
final class WebhookTest extends TestCase
{
    private const string SECRET = 'a-webhook-secret-of-at-least-32-characters';

    public function testVerifiesAValidSignature(): void
    {
        $payload = $this->payload();

        self::assertTrue(new Webhook(self::SECRET)->verify($payload, hash_hmac('sha256', $payload, self::SECRET)));
    }

    public function testAcceptsUppercaseAndSurroundingWhitespace(): void
    {
        $payload = $this->payload();

        self::assertTrue(new Webhook(self::SECRET)->verify($payload, ' ' . strtoupper(hash_hmac('sha256', $payload, self::SECRET)) . "\n"));
    }

    #[DataProvider('foreignSignatures')]
    public function testRejectsMissingEmptyAndForeignSignatures(?string $signature): void
    {
        self::assertFalse(new Webhook(self::SECRET)->verify($this->payload(), $signature));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function foreignSignatures(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'other secret' => [hash_hmac('sha256', 'x', 'another-secret-of-at-least-32-characters!')];
        yield 'not hex' => ['not a signature'];
        yield 'sha1 length' => [str_repeat('a', 40)];
    }

    public function testRejectsATamperedPayload(): void
    {
        $payload = $this->payload();
        $signature = hash_hmac('sha256', $payload, self::SECRET);

        self::assertFalse(new Webhook(self::SECRET)->verify($payload . ' ', $signature));
    }

    public function testReadsTheHeaderInEitherSpellingAndCase(): void
    {
        self::assertSame('abc', Webhook::signatureFrom(['X-Wordfence-Signature' => 'abc', 'X-Signature' => 'def']));
        self::assertSame('def', Webhook::signatureFrom(['x-signature' => 'def']));
        self::assertSame('ghi', Webhook::signatureFrom(['X-WORDFENCE-SIGNATURE' => ['ghi', 'other']]));
        self::assertNull(Webhook::signatureFrom(['Content-Type' => 'application/json']));
        self::assertNull(Webhook::signatureFrom(['X-Signature' => []]));
        self::assertSame('def', Webhook::signatureFrom(['X-Wordfence-Signature' => '  ', 'X-Signature' => 'def']));
    }

    public function testParsesCreatedReplacedAndDeleted(): void
    {
        $payload = $this->payload();

        $notification = new Webhook(self::SECRET)->parse($payload, hash_hmac('sha256', $payload, self::SECRET));

        self::assertSame(['00000000-0000-0000-0000-000000000001'], array_map(static fn($record): string => $record->id, $notification->created));
        self::assertSame(['00000000-0000-0000-0000-000000000002'], array_map(static fn($record): string => $record->id, $notification->replaced));
        self::assertSame(['00000000-0000-0000-0000-000000000003'], $notification->deleted);
    }

    public function testMissingListsAreEmpty(): void
    {
        $payload = '{"vulnerabilities":{"deleted":["x"]}}';

        $notification = new Webhook(self::SECRET)->parse($payload, hash_hmac('sha256', $payload, self::SECRET));

        self::assertSame([], $notification->created);
        self::assertSame([], $notification->replaced);
        self::assertSame(['x'], $notification->deleted);
    }

    public function testParseRejectsBeforeDecoding(): void
    {
        $this->expectException(InvalidSignatureException::class);

        new Webhook(self::SECRET)->parse('{not json', 'deadbeef');
    }

    public function testMalformedButSignedJsonIsASerializationError(): void
    {
        $payload = '{"vulnerabilities": {"created": [{"id": 1}]}}';

        $this->expectException(SerializationException::class);

        new Webhook(self::SECRET)->parse($payload, hash_hmac('sha256', $payload, self::SECRET));
    }

    public function testShortSecretIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Webhook(str_repeat('x', 31));
    }

    public function testSecretIsNotInDebugInfoOrExceptions(): void
    {
        $webhook = new Webhook(self::SECRET);

        self::assertStringNotContainsString(self::SECRET, print_r($webhook, true));

        try {
            $webhook->parse('{}', 'wrong');
            self::fail('No exception.');
        } catch (Throwable $exception) {
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, $exception->getTraceAsString());
        }
    }

    private function payload(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/Fixtures/webhook.json');
    }
}
