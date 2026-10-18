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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

/**
 * Reads both live feeds once. Needs WORDFENCE_API_KEY; with WORDFENCE_MEASURE=1
 * it also reports records, download size, time and peak memory on STDERR.
 */
#[CoversNothing]
final class FeedReadOnlyTest extends TestCase
{
    #[DataProvider('feeds')]
    public function testReadsTheWholeFeed(Feed $feed): void
    {
        $key = getenv('WORDFENCE_API_KEY');

        if (!\is_string($key) || $key === '') {
            self::markTestSkipped('WORDFENCE_API_KEY is not set.');
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

    /**
     * @return iterable<string, array{Feed}>
     */
    public static function feeds(): iterable
    {
        yield 'production' => [Feed::Production];
        yield 'scanner' => [Feed::Scanner];
    }
}
