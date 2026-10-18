<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Model;

use GoSuccess\Wordfence\Enum\CvssRating;

/**
 * The CVSS (v3.0 or v3.1) assessment of a vulnerability.
 */
final readonly class Cvss
{
    /**
     * @param string $vector The vector string, e.g. `CVSS:3.1/AV:N/AC:L/…`.
     * @param float  $score  0.0 to 10.0, higher is more severe.
     */
    public function __construct(
        public string $vector,
        public float $score,
        public CvssRating $rating,
    ) {}

    /**
     * @internal
     */
    public static function fromFields(Fields $fields): self
    {
        return new self($fields->string('vector'), $fields->float('score'), $fields->enum('rating', CvssRating::class));
    }
}
