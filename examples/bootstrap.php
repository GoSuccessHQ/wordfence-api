<?php

declare(strict_types=1);

/**
 * Shared setup of the examples: the autoloader and the API key from the
 * environment (WORDFENCE_API_KEY), e.g. `set -a; . ./.env; set +a`.
 */

require __DIR__ . '/../vendor/autoload.php';

$apiKey = getenv('WORDFENCE_API_KEY');

if (!is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "Set WORDFENCE_API_KEY first.\n");

    exit(1);
}

return $apiKey;
