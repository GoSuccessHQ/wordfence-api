<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Webhook;

use GoSuccess\Wordfence\Exception\InvalidSignatureException;
use GoSuccess\Wordfence\Exception\SerializationException;
use GoSuccess\Wordfence\Model\Fields;
use GoSuccess\Wordfence\Model\Vulnerability;
use InvalidArgumentException;
use JsonException;
use SensitiveParameter;

/**
 * Verifies and reads webhook notifications from Wordfence Intelligence.
 *
 * Wordfence signs the raw request body with HMAC-SHA-256 and the secret entered
 * for the webhook on wordfence.com, and sends the hex digest in the
 * `X-Wordfence-Signature` header (the documentation's prose calls it
 * `X-Signature`; both are read). Nothing is decoded before the signature
 * matches.
 */
final readonly class Webhook
{
    /** Wordfence recommends a secret of at least this many characters. */
    public const int MIN_SECRET_LENGTH = 32;

    /** @var list<string> Header names in the order they are looked up, lower case. */
    private const array SIGNATURE_HEADERS = ['x-wordfence-signature', 'x-signature'];

    /**
     * @throws InvalidArgumentException for a secret shorter than {@see MIN_SECRET_LENGTH}
     */
    public function __construct(
        #[SensitiveParameter]
        private string $secret,
    ) {
        if (\strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new InvalidArgumentException('The webhook secret must have at least ' . self::MIN_SECRET_LENGTH . ' characters.');
        }
    }

    /**
     * The signature from the request headers, whatever their case; for a header
     * that occurs several times, its first value.
     *
     * @param array<string, string|list<string>> $headers
     */
    public static function signatureFrom(array $headers): ?string
    {
        $byName = array_change_key_case($headers, \CASE_LOWER);

        foreach (self::SIGNATURE_HEADERS as $name) {
            $value = $byName[$name] ?? null;
            $value = \is_array($value) ? ($value[0] ?? null) : $value;

            if (\is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Whether the signature belongs to exactly this raw body; compared in
     * constant time, case-insensitive, surrounding whitespace ignored.
     */
    public function verify(string $payload, ?string $signature): bool
    {
        if ($signature === null || trim($signature) === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $this->secret), strtolower(trim($signature)));
    }

    /**
     * Verifies the signature, then reads the notification.
     *
     * @param string      $payload   The raw request body, exactly as received.
     * @param string|null $signature See {@see signatureFrom()}.
     *
     * @throws InvalidSignatureException before anything is decoded
     * @throws SerializationException    for a signed body that is not a valid notification
     */
    public function parse(string $payload, ?string $signature): Notification
    {
        if (!$this->verify($payload, $signature)) {
            throw new InvalidSignatureException('The webhook signature does not match the payload.');
        }

        try {
            $data = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SerializationException('The webhook payload is not valid JSON.');
        }

        $vulnerabilities = (\is_array($data) ? new Fields($data) : throw new SerializationException('The webhook payload is not a JSON object.'))
            ->nullableObject('vulnerabilities') ?? throw new SerializationException('Field vulnerabilities must be an object.');

        return new Notification(
            created: array_map(Vulnerability::fromFields(...), $vulnerabilities->objectList('created')),
            replaced: array_map(Vulnerability::fromFields(...), $vulnerabilities->objectList('replaced')),
            deleted: $vulnerabilities->strings('deleted'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['secret' => '***'];
    }
}
