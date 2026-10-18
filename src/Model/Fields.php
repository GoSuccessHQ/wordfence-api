<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Model;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use GoSuccess\Wordfence\Exception\SerializationException;

/**
 * Typed access to the decoded JSON of a record. Every failure names the path
 * of the field (e.g. `software[0].slug`), so a changed format is easy to find;
 * values themselves never appear in the message.
 *
 * @internal
 */
final readonly class Fields
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        private array $data,
        private string $path = '',
    ) {}

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        return \is_string($value) ? $value : throw $this->invalid($key, 'a string');
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return $value === null || \is_string($value) ? $value : throw $this->invalid($key, 'a string or null');
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->data[$key] ?? $default;

        return \is_bool($value) ? $value : throw $this->invalid($key, 'a boolean');
    }

    public function int(string $key): int
    {
        $value = $this->data[$key] ?? null;

        return \is_int($value) ? $value : throw $this->invalid($key, 'an integer');
    }

    public function float(string $key): float
    {
        $value = $this->data[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : throw $this->invalid($key, 'a number');
    }

    /**
     * A list of strings; a missing field is an empty list.
     *
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $values = [];

        foreach ($this->list($key) as $index => $value) {
            $values[] = \is_string($value) ? $value : throw $this->invalid("{$key}[{$index}]", 'a string');
        }

        return $values;
    }

    /**
     * A JSON array; a missing field is an empty list.
     *
     * @return list<mixed>
     */
    public function list(string $key): array
    {
        $value = $this->data[$key] ?? [];

        return \is_array($value) && array_is_list($value) ? $value : throw $this->invalid($key, 'a list');
    }

    /**
     * A JSON object as the fields of a nested record, or null when it is null or absent.
     */
    public function nullableObject(string $key): ?self
    {
        $value = $this->data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        return \is_array($value) ? $this->nested($value, $key) : throw $this->invalid($key, 'an object');
    }

    /**
     * The members of a JSON object, keyed by their name; a missing field is empty.
     *
     * @return array<string, self>
     */
    public function objects(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (!\is_array($value)) {
            throw $this->invalid($key, 'an object');
        }

        $members = [];

        foreach ($value as $name => $member) {
            $members[(string) $name] = \is_array($member) ? $this->nested($member, "{$key}[" . json_encode((string) $name) . ']') : throw $this->invalid("{$key}[" . json_encode((string) $name) . ']', 'an object');
        }

        return $members;
    }

    /**
     * The items of a JSON array of objects.
     *
     * @return list<self>
     */
    public function objectList(string $key): array
    {
        $items = [];

        foreach ($this->list($key) as $index => $item) {
            $items[] = \is_array($item) ? $this->nested($item, "{$key}[{$index}]") : throw $this->invalid("{$key}[{$index}]", 'an object');
        }

        return $items;
    }

    /**
     * @template T of BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    public function enum(string $key, string $enum): BackedEnum
    {
        return $enum::tryFrom($this->string($key)) ?? throw $this->invalid($key, 'one of the known values');
    }

    /**
     * A date in either format Wordfence uses, always UTC: `YYYY-MM-DD hh:mm:ss`
     * in the feed and ISO 8601 with microseconds and `Z` in webhooks.
     */
    public function nullableDate(string $key): ?DateTimeImmutable
    {
        $value = $this->nullableString($key);

        if ($value === null) {
            return null;
        }

        $utc = new DateTimeZone('UTC');

        foreach (['!Y-m-d H:i:s', '!Y-m-d\TH:i:s.u\Z', '!Y-m-d\TH:i:s\Z'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $utc);

            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return $date;
            }
        }

        throw $this->invalid($key, 'a date');
    }

    /**
     * Whether the record has the field at all (even as null).
     */
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    /**
     * The names of the members that hold objects, in their order.
     *
     * @return list<string>
     */
    public function objectKeys(): array
    {
        $keys = [];

        foreach ($this->data as $name => $value) {
            if (\is_array($value) && !array_is_list($value)) {
                $keys[] = (string) $name;
            }
        }

        return $keys;
    }

    /**
     * @param array<mixed> $data
     */
    private function nested(array $data, string $key): self
    {
        return new self($data, $this->field($key));
    }

    private function field(string $key): string
    {
        return $this->path === '' ? $key : "{$this->path}.{$key}";
    }

    private function invalid(string $key, string $expected): SerializationException
    {
        return new SerializationException("Field {$this->field($key)} must be {$expected}.");
    }
}
