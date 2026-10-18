<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Webhook;

use GoSuccess\Wordfence\Model\Vulnerability;

/**
 * What one webhook call reports: records added to Wordfence's database,
 * records that replace an earlier version, and IDs of removed records.
 */
final readonly class Notification
{
    /**
     * @param list<Vulnerability> $created  New records.
     * @param list<Vulnerability> $replaced Records that replace the one with the same ID.
     * @param list<string>        $deleted  UUIDs of records removed from the database.
     */
    public function __construct(
        public array $created,
        public array $replaced,
        public array $deleted,
    ) {}
}
