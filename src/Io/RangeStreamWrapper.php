<?php

declare(strict_types=1);

namespace CoolMS\Mime\Io;

use CoolMS\Mime\Exception\UnreadableSourceException;

use function fopen;
use function fread;
use function fseek;
use function is_array;
use function is_resource;
use function strlen;
use function max;
use function min;
use function stream_context_create;
use function stream_context_get_options;
use function stream_wrapper_register;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/**
 * A read-only stream over ONE BYTE RANGE of the source, without copying it.
 *
 * This is what makes the parser streaming rather than merely lazy. A part's body is
 * a range recorded during the scan; opening it hands back a real PHP stream that
 * reads those bytes and stops, so a transfer-decoding filter can be appended and a
 * 200MB attachment can be copied to disk with `stream_copy_to_stream` having never
 * existed in memory.
 *
 * **Every read seeks the shared source first**, because several part streams are
 * expected to be open over one message at once -- a caller iterating attachments
 * while reading the body -- and they would otherwise fight over one file position.
 * The seek is what makes them independent; it costs a syscall per read and buys
 * correctness that is otherwise impossible to get back.
 *
 * Registered under its own protocol on first use. The protocol name is namespaced so
 * it cannot collide with a wrapper some other library registered.
 */
final class RangeStreamWrapper
{
    public const string PROTOCOL = 'coolms-mime-range';

    private static bool $registered = false;

    /** @var resource|null set by PHP when the stream is opened */
    public $context;

    /** @var resource */
    private $source;

    private int $start = 0;

    private int $end = 0;

    private int $position = 0;

    /**
     * Open a stream over `[$start, $end)` of `$source`.
     *
     * @param resource $source
     *
     * @return resource
     */
    public static function open($source, int $start, int $end)
    {
        self::register();

        $context = stream_context_create([
            self::PROTOCOL => ['source' => $source, 'start' => $start, 'end' => $end],
        ]);

        $stream = fopen(self::PROTOCOL . '://range', 'rb', false, $context);
        if (false === $stream) {
            // Cannot happen with a registered wrapper and a valid context, and if it
            // ever does, an empty stream is a safer answer than a fatal in a parser.
            return fopen('php://memory', 'rb') ?: throw UnreadableSourceException::temporaryStreamUnavailable();
        }

        return $stream;
    }

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        // in_array on stream_get_wrappers would also answer, but registering twice is
        // the only failure mode and the flag already prevents it within this process.
        @stream_wrapper_register(self::PROTOCOL, self::class);
        self::$registered = true;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (!is_resource($this->context)) {
            return false;
        }

        $options = stream_context_get_options($this->context)[self::PROTOCOL] ?? null;
        if (!is_array($options) || !is_resource($options['source'] ?? null)) {
            return false;
        }

        $this->source = $options['source'];
        $this->start = max(0, (int) ($options['start'] ?? 0));
        $this->end = max($this->start, (int) ($options['end'] ?? 0));
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $remaining = ($this->end - $this->start) - $this->position;
        if ($remaining <= 0 || $count <= 0) {
            return '';
        }

        fseek($this->source, $this->start + $this->position);
        $read = fread($this->source, min($count, $remaining));
        if (false === $read) {
            return '';
        }
        $this->position += strlen($read);

        return $read;
    }

    public function stream_eof(): bool
    {
        return $this->position >= ($this->end - $this->start);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $length = $this->end - $this->start;
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $length + $offset,
            default => null,
        };

        if (null === $target || $target < 0 || $target > $length) {
            return false;
        }
        $this->position = $target;

        return true;
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        $size = $this->end - $this->start;

        return ['size' => $size, 7 => $size, 'mode' => 0o100444, 2 => 0o100444];
    }

    public function stream_close(): void
    {
        // The SOURCE is not closed here: it belongs to the message, several part
        // streams share it, and closing it would break every other one.
        $this->position = 0;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }
}
