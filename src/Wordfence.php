<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence;

use Generator;
use GoSuccess\Wordfence\Exception\ApiException;
use GoSuccess\Wordfence\Exception\SerializationException;
use GoSuccess\Wordfence\Exception\TransportException;
use GoSuccess\Wordfence\Feed\Feed;
use GoSuccess\Wordfence\Feed\ObjectStreamReader;
use GoSuccess\Wordfence\Http\CurlHttpClient;
use GoSuccess\Wordfence\Http\Method;
use GoSuccess\Wordfence\Http\Request;
use GoSuccess\Wordfence\Http\Response;
use GoSuccess\Wordfence\Http\StreamingHttpClient;
use GoSuccess\Wordfence\Model\Vulnerability;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Client for the Wordfence Intelligence v3 vulnerability data feed.
 *
 * Both feeds return every known vulnerability in one document. The client
 * downloads it into a temporary file and yields one record at a time, so the
 * feed never sits in memory as a whole. The file's name is removed as soon as
 * reading starts and after a failed download; only a process killed during the
 * download itself can leave a `wordfence-*` file in the temp dir.
 */
final class Wordfence
{
    public const string DEFAULT_BASE_URI = 'https://www.wordfence.com';

    /** At most this much of an error response is read for the exception. */
    private const int ERROR_BODY_BYTES = 65536;

    private readonly StreamingHttpClient $httpClient;

    /**
     * @param string                   $apiKey     Created on wordfence.com under Account → Integrations.
     * @param StreamingHttpClient|null $httpClient Defaults to {@see CurlHttpClient}.
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly string $apiKey,
        private readonly ClientOptions $options = new ClientOptions(),
        ?StreamingHttpClient $httpClient = null,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient($options->timeout, $options->connectTimeout);
    }

    /**
     * The production feed: fully analyzed records, keyed by UUID.
     *
     * @return Generator<string, Vulnerability>
     */
    public function production(): Generator
    {
        return $this->feed(Feed::Production);
    }

    /**
     * The scanner feed: minimal records for detection, keyed by UUID.
     *
     * @return Generator<string, Vulnerability>
     */
    public function scanner(): Generator
    {
        return $this->feed(Feed::Scanner);
    }

    /**
     * Downloads a feed and yields its records.
     *
     * @return Generator<string, Vulnerability>
     *
     * @throws ApiException           for an error response (a `429` is never retried)
     * @throws TransportException     when the download keeps failing
     * @throws SerializationException when the document or a record is malformed
     */
    public function feed(Feed $feed): Generator
    {
        $path = $this->download($feed);

        try {
            $stream = fopen($path, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Cannot read the downloaded feed.');
            }

            // Read from the handle only: the name goes at once, so nothing stays on
            // disk however the iteration ends, even if the process is killed.
            @unlink($path);

            try {
                foreach (new ObjectStreamReader()->entries($stream) as $uuid => $record) {
                    if (!\is_array($record)) {
                        throw new SerializationException('A record of the feed is not an object.');
                    }

                    yield $uuid => Vulnerability::fromArray($record);
                }
            } finally {
                fclose($stream);
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['apiKey' => '***', 'options' => $this->options, 'baseUri' => $this->baseUri];
    }

    /**
     * Downloads the feed into a temporary file and returns its path; retries
     * network failures and server errors, never a `429`.
     */
    private function download(Feed $feed): string
    {
        $request = new Request(
            method: Method::Get,
            uri: rtrim($this->baseUri, '/') . $feed->path(),
            headers: [
                'Authorization' => "Bearer {$this->apiKey}",
                'Accept' => 'application/json',
                'User-Agent' => $this->options->userAgent,
            ],
            timeout: $this->options->timeout,
            connectTimeout: $this->options->connectTimeout,
        );

        for ($attempt = 0; ; $attempt++) {
            // Silenced: tempnam() notices a fallback to the system temp dir, which a
            // strict error handler would turn into an exception after creating the file.
            $path = @tempnam(sys_get_temp_dir(), 'wordfence-');

            if ($path === false) {
                throw new RuntimeException('Cannot create a temporary file for the feed.');
            }

            try {
                $response = $this->httpClient->sendToFile($request, $path);
            } catch (Throwable $exception) {
                @unlink($path);

                if (!$exception instanceof TransportException || $attempt >= $this->options->maxRetries) {
                    throw $exception;
                }

                $this->wait($attempt);

                continue;
            }

            if ($response->isSuccessful) {
                return $path;
            }

            $error = ApiException::fromResponse($this->withBody($response, $path), $request);
            @unlink($path);

            if ($response->statusCode < 500 || $attempt >= $this->options->maxRetries) {
                throw $error;
            }

            $this->wait($attempt);
        }
    }

    /**
     * The error response with the beginning of its body from the file.
     */
    private function withBody(Response $response, string $path): Response
    {
        $body = @file_get_contents($path, false, null, 0, self::ERROR_BODY_BYTES);

        return new Response($response->statusCode, \is_string($body) ? $body : '', $response->headers, $response->reasonPhrase);
    }

    private function wait(int $attempt): void
    {
        $delay = min($this->options->maxRetryDelay, $this->options->retryBaseDelay * (2 ** $attempt));

        if ($delay > 0.0) {
            usleep((int) ($delay * 1_000_000));
        }
    }
}
