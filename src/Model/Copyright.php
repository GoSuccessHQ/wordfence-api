<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Model;

/**
 * The copyright claims on a record. Whoever shows a record must show these
 * notices with it; for MITRE's CVE data this is a condition of its license.
 */
final readonly class Copyright
{
    /**
     * @param list<CopyrightNotice> $notices In the order Wordfence sends them.
     */
    public function __construct(
        public ?string $message,
        public array $notices,
    ) {}

    /**
     * Reads both shapes: the feed keys each party by name (`defiant`, `mitre`),
     * webhooks send a list under `copyrights`.
     *
     * @internal
     */
    public static function fromFields(Fields $fields): self
    {
        if ($fields->has('copyrights')) {
            $notices = array_map(CopyrightNotice::fromFields(...), $fields->objectList('copyrights'));
        } else {
            $notices = [];

            foreach ($fields->objectKeys() as $party) {
                $notice = $fields->nullableObject($party);

                if ($notice !== null) {
                    $notices[] = CopyrightNotice::fromFields($notice);
                }
            }
        }

        return new self($fields->nullableString('message'), $notices);
    }
}
