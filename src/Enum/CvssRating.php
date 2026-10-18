<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Enum;

/**
 * The textual severity that goes with a CVSS score.
 */
enum CvssRating: string
{
    case None = 'None';
    case Low = 'Low';
    case Medium = 'Medium';
    case High = 'High';
    case Critical = 'Critical';
}
