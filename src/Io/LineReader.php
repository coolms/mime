<?php

declare(strict_types=1);

namespace CoolMS\Mime\Io;

use function fgets;
use function fseek;
use function rtrim;
use function strlen;

/**
 * Reads a message one line at a time, and -- the part that matters -- knows exactly
 * where each line began and ended in the source.
 *
 * Every structural decision this parser makes is a byte offset: where a header block
 * ends, where a part's body starts, where a boundary line begins. Those offsets are
 * what lets a part's body be a RANGE over the original bytes instead of a copy, so
 * the offsets have to come from the same place that reads the lines, not be
 * recomputed afterwards by counting characters in a string somebody already built.
 *
 * **Line endings are observed, not assumed.** RFC 5322 says CRLF; real mail arrives
 * with bare LF (a Unix mbox, a script that wrote "\n"), and occasionally with a bare
 * CR. `text()` gives the line without whatever terminated it, and
 * {@see terminatorLength()} says how many bytes that was -- which the body-range
 * arithmetic needs, because RFC 2046 makes the CRLF *before* a boundary delimiter
 * part of the delimiter rather than part of the preceding body.
 */
final class LineReader
{
    /** @var resource */
    private $stream;

    private int $offset;

    private int $lineStart = 0;

    private int $terminatorLength = 0;

    private ?string $text = null;

    /**
     * @param resource $stream
     */
    public function __construct($stream, int $offset)
    {
        $this->stream = $stream;
        $this->offset = $offset;
    }

    /**
     * Advance one line. Returns false at end of input, in which case the accessors
     * keep whatever the last successful read left.
     */
    public function next(): bool
    {
        fseek($this->stream, $this->offset);
        $raw = fgets($this->stream);
        if (false === $raw || '' === $raw) {
            return false;
        }

        $this->lineStart = $this->offset;
        $this->offset += strlen($raw);

        $stripped = rtrim($raw, "\r\n");
        $this->terminatorLength = strlen($raw) - strlen($stripped);
        $this->text = $stripped;

        return true;
    }

    /** The current line, without its terminator. */
    public function text(): string
    {
        return $this->text ?? '';
    }

    /** Byte offset where the current line begins. */
    public function lineStart(): int
    {
        return $this->lineStart;
    }

    /** Byte offset immediately after the current line's terminator -- where the next line begins. */
    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * How many bytes terminated the current line: 2 for CRLF, 1 for a bare LF or CR,
     * 0 for a final line with no terminator at all.
     */
    public function terminatorLength(): int
    {
        return $this->terminatorLength;
    }

    /**
     * Look at the next line without consuming it.
     *
     * Needed in exactly one place and worth the small cost there: a header field
     * continues onto the following line when that line begins with whitespace, and
     * deciding whether to fold requires seeing it before deciding to take it.
     */
    public function peek(): ?string
    {
        $mark = $this->offset;
        fseek($this->stream, $mark);
        $raw = fgets($this->stream);

        return false === $raw ? null : rtrim($raw, "\r\n");
    }

    /** Re-position, so a caller that has decided where a part begins can start there. */
    public function seekTo(int $offset): void
    {
        $this->offset = $offset;
        $this->lineStart = $offset;
        $this->terminatorLength = 0;
        $this->text = null;
    }
}
