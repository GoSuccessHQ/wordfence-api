<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Model;

/**
 * One party's copyright claim on a record and the license it grants.
 */
final readonly class CopyrightNotice
{
    public function __construct(
        public string $notice,
        public string $license,
        public string $licenseUrl,
    ) {}

    /**
     * @internal
     */
    public static function fromFields(Fields $fields): self
    {
        return new self($fields->string('notice'), $fields->string('license'), $fields->string('license_url'));
    }
}
