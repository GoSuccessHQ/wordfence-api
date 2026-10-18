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
     * Whether the version lies in the range, compared like WordPress compares
     * versions (`version_compare`). Trailing zero segments and a trailing dot
     * do not count (`1.0` equals `1.0.0`, and the bound `1.0.` means `1.0`);
     * an empty version is never contained.
     */
    public function contains(string $version): bool
    {
        $version = self::normalize($version);

        if ($version === '') {
            return false;
        }

        if ($this->fromVersion !== self::ANY) {
            $lower = version_compare($version, self::normalize($this->fromVersion));

            if ($lower < 0 || ($lower === 0 && !$this->fromInclusive)) {
                return false;
            }
        }

        if ($this->toVersion !== self::ANY) {
            $upper = version_compare($version, self::normalize($this->toVersion));

            if ($upper > 0 || ($upper === 0 && !$this->toInclusive)) {
                return false;
            }
        }

        return true;
    }

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

    private static function normalize(string $version): string
    {
        $version = rtrim(trim($version), '.');

        return $version === '' ? '' : (string) preg_replace('/(?<=\d)(?:\.0+)+$/', '', $version);
    }
}
