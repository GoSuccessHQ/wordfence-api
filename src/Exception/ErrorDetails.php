<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Exception;

/**
 * Normalizes the error bodies of the Wordfence API. Observed live on 2026-10-18:
 * `{"errors": [{"status": 401, "title": "The server encountered an error
 * processing your request.", "detail": "Invalid API key supplied."}]}`. The
 * `detail` of every error forms the message, the `title` only where a detail
 * is missing; plain text or HTML (e.g. from a proxy) is shortened. Wordfence
 * sends no error codes.
 *
 * @internal
 */
final readonly class ErrorDetails
{
    private const int MAX_MESSAGE_BYTES = 500;

    private function __construct(
        public ?string $message,
        public ?string $errorCode,
    ) {}

    public static function parse(string $body): self
    {
        if (trim($body) === '') {
            return new self(null, null);
        }

        $decoded = json_decode($body, true);

        if (!\is_array($decoded)) {
            // Plain text or an HTML error page.
            return new self(self::excerpt($body), null);
        }

        $errors = $decoded['errors'] ?? null;
        $texts = [];

        foreach (\is_array($errors) ? $errors : [] as $error) {
            if (!\is_array($error)) {
                continue;
            }

            $text = self::text($error['detail'] ?? null) ?? self::text($error['title'] ?? null);

            if ($text !== null) {
                $texts[] = $text;
            }
        }

        return new self($texts === [] ? null : self::excerpt(implode('; ', $texts)), null);
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function excerpt(string $text): string
    {
        $text = trim($text);

        if (\strlen($text) <= self::MAX_MESSAGE_BYTES) {
            return $text;
        }

        $cut = substr($text, 0, self::MAX_MESSAGE_BYTES);

        // Drop a trailing, incomplete multi-byte sequence (ext-mbstring is not required).
        return (preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $cut) ?? $cut) . '…';
    }
}
