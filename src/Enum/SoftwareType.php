<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Enum;

/**
 * The kind of WordPress software a vulnerability affects.
 */
enum SoftwareType: string
{
    case Core = 'core';
    case Plugin = 'plugin';
    case Theme = 'theme';
}
