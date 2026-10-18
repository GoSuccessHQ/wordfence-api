<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Tests\Unit\Feed;

use GoSuccess\Wordfence\Exception\SerializationException;
use GoSuccess\Wordfence\Feed\ObjectStreamReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObjectStreamReader::class)]
final class ObjectStreamReaderTest extends TestCase
{
    public function testYieldsEachMemberInOrder(): void
    {
        $entries = $this->read('{"a": {"id": "a", "n": [1, 2]}, "b": {"id": "b"} , "c" : {}}');

        self::assertSame(['a' => ['id' => 'a', 'n' => [1, 2]], 'b' => ['id' => 'b'], 'c' => []], $entries);
    }

    public function testEmptyObjectYieldsNothing(): void
    {
        self::assertSame([], $this->read(" \n{ }\n"));
    }

    public function testStringsWithBracesQuotesAndEscapes(): void
    {
        $value = ['title' => '{"x":"}"} [ ] \\ "quoted"', 'u' => 'ä ü €', 'escaped' => "line\nbreak"];
        $json = '{"k\\"ey": ' . json_encode($value) . ', "ä": {"v": "\\u00e4\\\\"}}';

        self::assertSame(['k"ey' => $value, 'ä' => ['v' => 'ä\\']], $this->read($json));
    }

    #[DataProvider('bufferSizes')]
    public function testWorksWithEveryBufferSize(int $bufferBytes): void
    {
        $json = '{"first": {"t": "a{b}c\\"d\\\\", "u": "äöü 😀"}, "second": [{"x": null}, true, 1.5], "third": "plain"}';

        self::assertSame(
            ['first' => ['t' => 'a{b}c"d\\', 'u' => 'äöü 😀'], 'second' => [['x' => null], true, 1.5], 'third' => 'plain'],
            $this->read($json, $bufferBytes),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function bufferSizes(): iterable
    {
        foreach ([1, 2, 3, 7, 64] as $size) {
            yield "{$size} bytes" => [$size];
        }
    }

    #[DataProvider('malformed')]
    public function testRejectsWhatIsNotAnObject(string $json): void
    {
        $this->expectException(SerializationException::class);

        $this->read($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'array' => ['[{"a": 1}]'];
        yield 'empty' => [''];
        yield 'html' => ['<!DOCTYPE html><html><body>Bad gateway</body></html>'];
        yield 'truncated value' => ['{"a": {"id": "a"'];
        yield 'truncated after member' => ['{"a": {"id": "a"},'];
        yield 'missing colon' => ['{"a" {"id": "a"}}'];
        yield 'key not a string' => ['{a: 1}'];
        yield 'trailing garbage' => ['{"a": 1} x'];
        yield 'invalid value' => ['{"a": {"id": tru}}'];
        yield 'unterminated string' => ['{"a": "abc'];
    }

    public function testMemoryStaysAtTheSizeOfOneRecord(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertNotFalse($stream);
        $record = json_encode(['title' => str_repeat('x', 2_000), 'software' => [['slug' => 'example']]]);
        fwrite($stream, '{');

        for ($i = 0; $i < 10_000; $i++) {
            fwrite($stream, ($i === 0 ? '' : ',') . "\"id-{$i}\": {$record}");
        }

        fwrite($stream, '}');
        rewind($stream);
        $count = 0;
        memory_reset_peak_usage();
        $before = memory_get_usage();

        foreach (new ObjectStreamReader()->entries($stream) as $value) {
            $count++;
        }

        fclose($stream);

        self::assertSame(10_000, $count);
        // The stream holds about 20 MB; the reader may keep the buffer and one record.
        self::assertLessThan(2 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    public function testTheMessageNamesTheOffsetButNotTheContent(): void
    {
        try {
            $this->read('{"secret-key": 1 x}');
            self::fail('No exception.');
        } catch (SerializationException $exception) {
            self::assertStringContainsString('offset', $exception->getMessage());
            self::assertStringNotContainsString('secret-key', $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $json, int $bufferBytes = 65536): array
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        fwrite($stream, $json);
        rewind($stream);

        $entries = [];

        foreach (new ObjectStreamReader()->entries($stream, $bufferBytes) as $key => $value) {
            $entries[$key] = $value;
        }

        fclose($stream);

        return $entries;
    }
}
