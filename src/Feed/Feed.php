<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Feed;

/**
 * The two versions of the vulnerability data feed.
 */
enum Feed: string
{
    /** Fully analyzed records with every field. */
    case Production = 'production';

    /** Minimal records for detection only. */
    case Scanner = 'scanner';

    /**
     * Path of the endpoint below the base URI.
     *
     * @return non-empty-string
     */
    public function path(): string
    {
        return "/api/intelligence/v3/vulnerabilities/{$this->value}";
    }
}
