<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Model;

use GoSuccess\Wordfence\Enum\SoftwareType;

/**
 * A piece of software a vulnerability affects: WordPress itself, a plugin or a theme.
 */
final readonly class Software
{
    /**
     * @param string|null                 $id               Wordfence's ID of the software; only webhooks send it.
     * @param string                      $slug             The slug on wordpress.org (`core` software has its own).
     * @param array<string, VersionRange> $affectedVersions Keyed by a label for display, e.g. `1.0.0 - 1.2.3`.
     * @param list<string>                $patchedVersions  Versions that contain the fix.
     * @param string|null                 $remediation      Advice; only the production feed and webhooks send it.
     */
    public function __construct(
        public ?string $id,
        public SoftwareType $type,
        public string $name,
        public string $slug,
        public array $affectedVersions,
        public bool $patched,
        public array $patchedVersions,
        public ?string $remediation,
    ) {}

    /**
     * @internal
     */
    public static function fromFields(Fields $fields): self
    {
        return new self(
            id: $fields->nullableString('id'),
            type: $fields->enum('type', SoftwareType::class),
            name: $fields->string('name'),
            slug: $fields->string('slug'),
            affectedVersions: array_map(VersionRange::fromFields(...), $fields->objects('affected_versions')),
            patched: $fields->bool('patched'),
            patchedVersions: $fields->strings('patched_versions'),
            remediation: $fields->nullableString('remediation'),
        );
    }
}
