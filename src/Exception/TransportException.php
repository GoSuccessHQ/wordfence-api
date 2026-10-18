<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Exception;

use RuntimeException;

/**
 * The request could not be delivered or the response could not be received
 * (DNS failure, connection error, timeout, …).
 */
final class TransportException extends RuntimeException implements WordfenceException {}
