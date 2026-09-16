<?php

declare(strict_types=1);

namespace CoolMS\Mime\Io;

use CoolMS\Mime\Exception\UnreadableSourceException;

use function fopen;
use function fseek;
use function fstat;
use function fwrite;
use function get_debug_type;
use function is_array;
use function is_resource;
use function is_string;
use function rewind;
use function str_contains;
use function stream_copy_to_stream;
use function stream_get_contents;
use function stream_get_meta_data;

/**
 * The bytes a message is parsed from, as a SEEKABLE stream.
 *
 * **Why seekable is a requirement rather than a convenience.** The whole point of
 * this parser is that a part's body is never copied: parsing records a byte RANGE
 * per part, and {@see \CoolMS\Mime\Part::stream()} later opens a bounded view over
 * these same bytes. A range means nothing on a stream that cannot seek, so a
 * non-seekable source (a socket, a pipe) is buffered into `php://temp` ONCE, up
 * front, rather than each part being buffered separately later.
 *
 * `php://temp` keeps small messages in memory and spills large ones to disk by
 * itself, which is the behaviour wanted: a 40-byte notification costs no file, and a
 * 200MB message with video attached does not cost 200MB of memory.
 */
final class Source
{
    /** @var resource */
    private $stream;

    /**
     * @param resource $stream a readable, seekable stream positioned anywhere
     */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    public static function fromString(string $raw): self
    {
        $stream = fopen('php://temp', 'r+b');
        if (false === $stream) {
            throw UnreadableSourceException::temporaryStreamUnavailable();
        }
        fwrite($stream, $raw);
        rewind($stream);

        return new self($stream);
    }

    /**
     * @param resource|string $source
     */
    public static function from(mixed $source): self
    {
        if (is_string($source)) {
            return self::fromString($source);
        }
        if (!is_resource($source)) {
            throw UnreadableSourceException::notAStream(get_debug_type($source));
        }

        $meta = stream_get_meta_data($source);
        if (!str_contains($meta['mode'], 'r') && !str_contains($meta['mode'], '+')) {
            throw UnreadableSourceException::notReadable();
        }

        if (true === ($meta['seekable'] ?? false)) {
            return new self($source);
        }

        // Not seekable: buffer once, here, rather than forcing every later part read
        // to buffer its own copy.
        $buffer = fopen('php://temp', 'r+b');
        if (false === $buffer) {
            throw UnreadableSourceException::temporaryStreamUnavailable();
        }
        stream_copy_to_stream($source, $buffer);
        rewind($buffer);

        return new self($buffer);
    }

    /** @return resource */
    public function stream()
    {
        return $this->stream;
    }

    /** A reader positioned at the start of the message. */
    public function reader(): LineReader
    {
        return new LineReader($this->stream, 0);
    }

    /**
     * The bytes of one range, read whole.
     *
     * Used for the short things -- a header block, a small text part -- where the
     * cost of a copy is trivial and a stream is ceremony. Attachment bodies go
     * through {@see \CoolMS\Mime\Io\RangeStream} instead.
     */
    public function readRange(int $start, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        fseek($this->stream, $start);
        $read = stream_get_contents($this->stream, $length);

        return false === $read ? '' : $read;
    }

    public function size(): int
    {
        $stat = fstat($this->stream);

        return is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : 0;
    }
}
