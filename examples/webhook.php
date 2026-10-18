<?php

declare(strict_types=1);

/**
 * A minimal webhook endpoint: verify the signature, then handle the records.
 * The secret is the one entered for the webhook on wordfence.com.
 */

use GoSuccess\Wordfence\Exception\InvalidSignatureException;
use GoSuccess\Wordfence\Exception\SerializationException;
use GoSuccess\Wordfence\Webhook\Webhook;

require __DIR__ . '/../vendor/autoload.php';

$secret = getenv('WORDFENCE_WEBHOOK_SECRET');
$payload = file_get_contents('php://input');

if (!is_string($secret) || !is_string($payload)) {
    http_response_code(500);

    exit;
}

// getallheaders() exists under Apache, FPM and the built-in server.
/** @var array<string, string> $headers */
$headers = function_exists('getallheaders') ? getallheaders() : [];

try {
    $notification = new Webhook($secret)->parse($payload, Webhook::signatureFrom($headers));
} catch (InvalidSignatureException) {
    http_response_code(401);

    exit;
} catch (SerializationException) {
    http_response_code(400);

    exit;
}

foreach ([...$notification->created, ...$notification->replaced] as $vulnerability) {
    error_log("Wordfence: {$vulnerability->id} {$vulnerability->title}");
}

foreach ($notification->deleted as $id) {
    error_log("Wordfence: removed {$id}");
}

http_response_code(204);
