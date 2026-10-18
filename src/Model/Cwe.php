<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Model;

/**
 * The Common Weakness Enumeration entry of a vulnerability.
 */
final readonly class Cwe
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
    ) {}

    /**
     * @internal
     */
    public static function fromFields(Fields $fields): self
    {
        return new self($fields->int('id'), $fields->string('name'), $fields->nullableString('description'));
    }
}
