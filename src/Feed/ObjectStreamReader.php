<?php

declare(strict_types=1);

namespace GoSuccess\Wordfence\Feed;

use Generator;
use GoSuccess\Wordfence\Exception\SerializationException;
use JsonException;

/**
 * Reads a JSON document whose top level is an object, one member at a time.
 *
 * The feed is a single object keyed by vulnerability UUID; decoding it at once
 * would hold the whole feed in memory several times over. This reader finds
 * the end of each member's value (tracking strings, escapes and nesting) and
 * decodes only that value, so memory stays at the size of one record plus the
 * read buffer.
 */
final class ObjectStreamReader
{
    private const string WHITESPACE = " \t\n\r";

    /** @var resource */
    private $stream;

    private string $buffer = '';

    private int $position = 0;

    /** Bytes dropped from the front of the buffer so far, for error offsets. */
    private int $dropped = 0;

    /** @var positive-int */
    private int $bufferBytes = 65536;

    /**
     * Yields each member of the top-level object as name => decoded value.
     *
     * @param resource $stream      Read from its current position.
     * @param int      $bufferBytes How much to read at a time; at least 1.
     *
     * @return Generator<string, mixed>
     *
     * @throws SerializationException if the document is not a well-formed object;
     *                                the message names the byte offset, never the content
     */
    public function entries($stream, int $bufferBytes = 65536): Generator
    {
        $this->stream = $stream;
        $this->buffer = '';
        $this->position = 0;
        $this->dropped = 0;
        $this->bufferBytes = max(1, $bufferBytes);

        $this->expect('{');

        if ($this->peekSignificant() === '}') {
            $this->position++;
        } else {
            while (true) {
                $this->compact();
                $this->skipWhitespace();
                $key = $this->readKey();
                $this->expect(':');
                $this->skipWhitespace();

                yield $key => $this->decode($this->readValue());

                $next = $this->peekSignificant();
                $this->position++;

                if ($next === '}') {
                    break;
                }

                if ($next !== ',') {
                    throw $this->malformed('expected "," or "}"');
                }
            }
        }

        if ($this->peekSignificant() !== null) {
            throw $this->malformed('unexpected data after the object');
        }
    }

    /**
     * The member name: a JSON string.
     */
    private function readKey(): string
    {
        if ($this->peek() !== '"') {
            throw $this->malformed('expected a member name');
        }

        $key = $this->decode($this->readString());

        return \is_string($key) ? $key : throw $this->malformed('expected a member name');
    }

    /**
     * The raw text of one value, ending before the following `,` or `}`.
     */
    private function readValue(): string
    {
        $first = $this->peek();

        if ($first === '"') {
            return $this->readString();
        }

        if ($first === '{' || $first === '[') {
            return $this->readNested();
        }

        $start = $this->position;

        while (true) {
            $this->position += strcspn($this->buffer, ',}]' . self::WHITESPACE, $this->position);

            if ($this->position < \strlen($this->buffer) || !$this->fill()) {
                break;
            }
        }

        if ($this->position === $start) {
            throw $this->malformed('expected a value');
        }

        return substr($this->buffer, $start, $this->position - $start);
    }

    /**
     * An object or array including everything nested inside.
     */
    private function readNested(): string
    {
        $start = $this->position;
        $depth = 0;

        while (true) {
            $this->position += strcspn($this->buffer, '{}[]"', $this->position);

            if ($this->position >= \strlen($this->buffer)) {
                if (!$this->fill()) {
                    throw $this->malformed('unexpected end inside a value');
                }

                continue;
            }

            $char = $this->buffer[$this->position];

            if ($char === '"') {
                $this->skipString();

                continue;
            }

            $this->position++;
            $depth += ($char === '{' || $char === '[') ? 1 : -1;

            if ($depth === 0) {
                return substr($this->buffer, $start, $this->position - $start);
            }
        }
    }

    /**
     * A string including its quotes.
     */
    private function readString(): string
    {
        $start = $this->position;
        $this->skipString();

        return substr($this->buffer, $start, $this->position - $start);
    }

    /**
     * Moves past a string that starts at the current position.
     */
    private function skipString(): void
    {
        $this->position++;

        while (true) {
            $this->position += strcspn($this->buffer, '"\\', $this->position);

            if ($this->position >= \strlen($this->buffer)) {
                if (!$this->fill()) {
                    throw $this->malformed('unterminated string');
                }

                continue;
            }

            if ($this->buffer[$this->position] === '"') {
                $this->position++;

                return;
            }

            // A backslash escapes the next byte; make sure it is in the buffer.
            while ($this->position + 1 >= \strlen($this->buffer)) {
                if (!$this->fill()) {
                    throw $this->malformed('unterminated string');
                }
            }

            $this->position += 2;
        }
    }

    private function decode(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->malformed('invalid value');
        }
    }

    private function expect(string $char): void
    {
        if ($this->peekSignificant() !== $char) {
            throw $this->malformed("expected \"{$char}\"");
        }

        $this->position++;
    }

    /**
     * The next byte that is not whitespace, without consuming it; null at the end.
     */
    private function peekSignificant(): ?string
    {
        $this->skipWhitespace();

        return $this->peek();
    }

    private function skipWhitespace(): void
    {
        while (true) {
            $this->position += strspn($this->buffer, self::WHITESPACE, $this->position);

            if ($this->position < \strlen($this->buffer) || !$this->fill()) {
                return;
            }
        }
    }

    private function peek(): ?string
    {
        while ($this->position >= \strlen($this->buffer)) {
            if (!$this->fill()) {
                return null;
            }
        }

        return $this->buffer[$this->position];
    }

    /**
     * Drops what was consumed; called only between members, so the value being
     * read always stays whole in the buffer.
     */
    private function compact(): void
    {
        if ($this->position === 0) {
            return;
        }

        $this->buffer = substr($this->buffer, $this->position);
        $this->dropped += $this->position;
        $this->position = 0;
    }

    /**
     * Appends the next chunk; false at the end of the stream.
     */
    private function fill(): bool
    {
        $chunk = feof($this->stream) ? false : fread($this->stream, $this->bufferBytes);

        if ($chunk === false || $chunk === '') {
            return false;
        }

        $this->buffer .= $chunk;

        return true;
    }

    private function malformed(string $reason): SerializationException
    {
        return new SerializationException(\sprintf('The feed is not a well-formed JSON object: %s at offset %d.', $reason, $this->dropped + $this->position));
    }
}
