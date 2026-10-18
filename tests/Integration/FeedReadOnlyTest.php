<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Tests\Integration;

use GoSuccess\Wordfence\ClientOptions;
use GoSuccess\Wordfence\Enum\SoftwareType;
use GoSuccess\Wordfence\Exception\RateLimitException;
use GoSuccess\Wordfence\Feed\Feed;
use GoSuccess\Wordfence\Http\CurlHttpClient;
use GoSuccess\Wordfence\Http\Request;
use GoSuccess\Wordfence\Http\Response;
use GoSuccess\Wordfence\Http\StreamingHttpClient;
use GoSuccess\Wordfence\Wordfence;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

/**
 * Reads ONE live feed, chosen with WORDFENCE_FEED (`production` or `scanner`),
 * and needs WORDFENCE_API_KEY; without both it is skipped. Never both feeds in
 * one run: a second full download right after the first exceeds the key's rate
 * limit, and repeated excess may get the key suspended (Wordfence, 2026-10-18).
 * With WORDFENCE_MEASURE=1 it reports records, size, time and peak memory.
 */
#[CoversNothing]
final class FeedReadOnlyTest extends TestCase
{
    public function testReadsTheChosenFeed(): void
    {
        $key = getenv('WORDFENCE_API_KEY');
        $feed = Feed::tryFrom((string) getenv('WORDFENCE_FEED'));

        if (!\is_string($key) || $key === '' || $feed === null) {
            self::markTestSkipped('Set WORDFENCE_API_KEY and WORDFENCE_FEED (production or scanner); one feed per run.');
        }

        $http = new class implements StreamingHttpClient {
            public int $bytes = 0;

            private CurlHttpClient $curl;

            public function __construct()
            {
                $this->curl = new CurlHttpClient();
            }

            public function send(#[SensitiveParameter] Request $request): Response
            {
                return $this->curl->send($request);
            }

            public function sendToFile(#[SensitiveParameter] Request $request, string $path): Response
            {
                $response = $this->curl->sendToFile($request, $path);
                clearstatcache(true, $path);
                $this->bytes = (int) filesize($path);

                return $response;
            }
        };

        $client = new Wordfence($key, new ClientOptions(), $http);
        $started = microtime(true);
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $count = 0;
        $types = [];

        try {
            foreach ($client->feed($feed) as $uuid => $record) {
                self::assertSame($uuid, $record->id);
                $count++;

                foreach ($record->software as $software) {
                    $types[$software->type->value] = true;
                }
            }
        } catch (RateLimitException $exception) {
            // The limit per key is tight: two full downloads in a row already hit it (2026-10-18).
            self::markTestSkipped('Rate limit of the API key reached; retry after ' . ($exception->retryAfter ?? '?') . ' s.');
        }

        self::assertGreaterThan(1000, $count);
        self::assertArrayHasKey(SoftwareType::Plugin->value, $types);

        if (getenv('WORDFENCE_MEASURE') === '1') {
            fwrite(\STDERR, \sprintf(
                "\n%s: %d records, %.1f MB downloaded, %.1f s, peak %.1f MB above start\n",
                $feed->value,
                $count,
                $http->bytes / 1_048_576,
                microtime(true) - $started,
                (memory_get_peak_usage() - $before) / 1_048_576,
            ));
        }
    }
}
