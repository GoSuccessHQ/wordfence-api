<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Model;

/**
 * A range of affected versions. `*` on either side means "any version" on that side.
 */
final readonly class VersionRange
{
    public const string ANY = '*';

    public function __construct(
        public string $fromVersion,
        public bool $fromInclusive,
        public string $toVersion,
        public bool $toInclusive,
    ) {}

    /**
     * @internal
     */
    public static function fromFields(Fields $fields): self
    {
        return new self(
            fromVersion: $fields->string('from_version'),
            fromInclusive: $fields->bool('from_inclusive'),
            toVersion: $fields->string('to_version'),
            toInclusive: $fields->bool('to_inclusive'),
        );
    }
}
