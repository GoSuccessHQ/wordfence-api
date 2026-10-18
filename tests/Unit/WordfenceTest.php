<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Tests\Unit;

use GoSuccess\Wordfence\ClientOptions;
use GoSuccess\Wordfence\Exception\AuthenticationException;
use GoSuccess\Wordfence\Exception\RateLimitException;
use GoSuccess\Wordfence\Exception\SerializationException;
use GoSuccess\Wordfence\Exception\ServerException;
use GoSuccess\Wordfence\Exception\TransportException;
use GoSuccess\Wordfence\Feed\Feed;
use GoSuccess\Wordfence\Http\Response;
use GoSuccess\Wordfence\Model\Vulnerability;
use GoSuccess\Wordfence\Tests\Support\MockHttpClient;
use GoSuccess\Wordfence\Wordfence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Wordfence::class)]
#[CoversClass(Feed::class)]
final class WordfenceTest extends TestCase
{
    private const string KEY = 'wf-secret-key-123';

    public function testSendsTheBearerKeyToTheProductionUrl(): void
    {
        $http = new MockHttpClient(new Response(200, $this->feed()));

        iterator_to_array($this->client($http)->production());

        $request = $http->requests[0];
        self::assertSame('GET', $request->method->value);
        self::assertSame('https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production', $request->uri);
        self::assertSame('Bearer ' . self::KEY, $request->headers['Authorization']);
        self::assertSame('application/json', $request->headers['Accept']);
        self::assertSame(ClientOptions::DEFAULT_USER_AGENT, $request->headers['User-Agent']);
        self::assertSame(300.0, $request->timeout);
    }

    public function testScannerUsesTheScannerUrl(): void
    {
        $http = new MockHttpClient(new Response(200, '{}'));

        iterator_to_array($this->client($http)->scanner());

        self::assertSame('https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner', $http->requests[0]->uri);
    }

    public function testYieldsVulnerabilitiesKeyedByUuid(): void
    {
        $records = iterator_to_array($this->client(new MockHttpClient(new Response(200, $this->feed())))->production());

        self::assertSame(['848ccbdc-c6f1-480f-a272-cd459e706713', '00000000-0000-0000-0000-000000000001'], array_keys($records));
        self::assertContainsOnlyInstancesOf(Vulnerability::class, $records);
        self::assertSame('example', $records['848ccbdc-c6f1-480f-a272-cd459e706713']->software[0]->slug);
    }

    public function testUnauthorizedRaisesAuthenticationException(): void
    {
        $http = new MockHttpClient(new Response(401, '{"errors":[{"status":401,"detail":"Invalid API key supplied."}]}'));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid API key supplied.');

        iterator_to_array($this->client($http)->production());
    }

    public function testRateLimitRaisesWithRetryAfterAndIsNotRetried(): void
    {
        $http = new MockHttpClient(new Response(429, '', ['retry-after' => '120']));

        try {
            iterator_to_array($this->client($http)->production());
            self::fail('No exception.');
        } catch (RateLimitException $exception) {
            self::assertSame(120, $exception->retryAfter);
            self::assertSame(1, $http->callCount());
        }
    }

    public function testRetriesServerErrorsAndTransportFailures(): void
    {
        $http = new MockHttpClient(new Response(503, 'busy'), new TransportException('reset'), new Response(200, '{}'));

        self::assertSame([], iterator_to_array($this->client($http)->production()));
        self::assertSame(3, $http->callCount());
    }

    public function testGivesUpAfterTheRetries(): void
    {
        $http = new MockHttpClient(new Response(502), new Response(502), new Response(502));

        $this->expectException(ServerException::class);

        iterator_to_array($this->client($http)->production());
    }

    public function testHtmlWith200RaisesSerializationException(): void
    {
        $http = new MockHttpClient(new Response(200, '<!DOCTYPE html><html><body>Please wait</body></html>'));

        $this->expectException(SerializationException::class);

        iterator_to_array($this->client($http)->production());
    }

    public function testARecordThatIsNotAnObjectIsRejected(): void
    {
        $http = new MockHttpClient(new Response(200, '{"a": "text"}'));

        $this->expectException(SerializationException::class);

        iterator_to_array($this->client($http)->production());
    }

    public function testTempFileIsRemovedAfterFullIteration(): void
    {
        $http = new MockHttpClient(new Response(200, $this->feed()));

        iterator_to_array($this->client($http)->production());

        self::assertCount(1, $http->files);
        self::assertFileDoesNotExist($http->files[0]);
    }

    public function testTempFileIsRemovedAfterBreak(): void
    {
        $http = new MockHttpClient(new Response(200, $this->feed()));

        foreach ($this->client($http)->production() as $record) {
            self::assertFileExists($http->files[0]);

            break;
        }

        self::assertFileDoesNotExist($http->files[0]);
    }

    public function testTempFilesAreRemovedAfterAnError(): void
    {
        $http = new MockHttpClient(new Response(503), new Response(200, '{"a": {"id": 1}}'));

        try {
            iterator_to_array($this->client($http)->production());
            self::fail('No exception.');
        } catch (SerializationException) {
        }

        self::assertCount(2, $http->files);
        self::assertFileDoesNotExist($http->files[0]);
        self::assertFileDoesNotExist($http->files[1]);
    }

    public function testKeyIsNotInDebugInfoOrExceptions(): void
    {
        $client = $this->client(new MockHttpClient(new Response(401, 'nope')));

        self::assertStringNotContainsString(self::KEY, print_r($client, true));

        try {
            iterator_to_array($client->production());
            self::fail('No exception.');
        } catch (Throwable $exception) {
            self::assertStringNotContainsString(self::KEY, $exception->getMessage());
            self::assertStringNotContainsString(self::KEY, $exception->getTraceAsString());
        }
    }

    private function client(MockHttpClient $http): Wordfence
    {
        return new Wordfence(self::KEY, new ClientOptions(retryBaseDelay: 0.0), $http);
    }

    private function feed(): string
    {
        $fixtures = \dirname(__DIR__) . '/Fixtures';

        return '{"848ccbdc-c6f1-480f-a272-cd459e706713": ' . file_get_contents("{$fixtures}/production-record.json")
            . ', "00000000-0000-0000-0000-000000000001": ' . file_get_contents("{$fixtures}/webhook-record.json") . '}';
    }
}
