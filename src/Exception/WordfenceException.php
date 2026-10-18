<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception thrown by this library, so
 * callers can catch all of them with a single `catch (WordfenceException $e)`.
 */
interface WordfenceException extends Throwable {}
