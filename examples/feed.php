<?php

declare(strict_types=1);

/**
 * Lists the vulnerabilities of one plugin version from the production feed.
 *
 * Usage: php examples/feed.php <plugin-slug> <version>
 */

use GoSuccess\Wordfence\Enum\SoftwareType;
use GoSuccess\Wordfence\Exception\RateLimitException;
use GoSuccess\Wordfence\Wordfence;

$apiKey = require __DIR__ . '/bootstrap.php';
assert(is_string($apiKey));

$slug = $argv[1] ?? 'contact-form-7';
$version = $argv[2] ?? '5.0';

try {
    foreach (new Wordfence($apiKey)->production() as $vulnerability) {
        foreach ($vulnerability->software as $software) {
            if ($software->type === SoftwareType::Plugin && $software->slug === $slug && $software->affects($version)) {
                printf("%s  %s  %s\n", $vulnerability->cvss->rating->value ?? '-', $vulnerability->id, $vulnerability->title);
            }
        }
    }
} catch (RateLimitException) {
    fwrite(STDERR, "The API key's rate limit is reached; try again later.\n");

    exit(1);
}
