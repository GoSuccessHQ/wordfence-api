<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Exception;

use RuntimeException;

/**
 * A webhook request whose signature is missing or does not match the payload.
 * Such a request must be rejected unread; it may not come from Wordfence.
 */
final class InvalidSignatureException extends RuntimeException implements WordfenceException {}
