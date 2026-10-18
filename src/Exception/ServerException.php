<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Exception;

/**
 * Wordfence failed to process the request (HTTP 5xx).
 */
final class ServerException extends ApiException {}
