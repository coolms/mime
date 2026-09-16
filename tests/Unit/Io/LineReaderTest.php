<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Unit\Io;

use CoolMS\Mime\Io\Source;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The reader is where every structural decision in this parser gets its numbers, so
 * the numbers are what is asserted -- not just the text.
 */
final class LineReaderTest extends TestCase
{
    #[Test]
    public function itReportsWhereEachLineBeginsAndEnds(): void
    {
        $reader = Source::fromString("ab\r\ncd\r\n")->reader();

        self::assertTrue($reader->next());
        self::assertSame('ab', $reader->text());
        self::assertSame(0, $reader->lineStart());
        self::assertSame(4, $reader->offset(), 'the next line starts after the CRLF');
        self::assertSame(2, $reader->terminatorLength());

        self::assertTrue($reader->next());
        self::assertSame('cd', $reader->text());
        self::assertSame(4, $reader->lineStart());
        self::assertSame(8, $reader->offset());

        self::assertFalse($reader->next(), 'and then it is done');
    }

    /**
     * Bare LF is not a curiosity -- it is what a Unix mbox and any script that wrote
     * "\n" produces, and the terminator LENGTH is what the body-range arithmetic
     * subtracts, so getting it wrong shifts every part by a byte.
     */
    #[Test]
    public function itObservesTheLineEndingRatherThanAssumingCrlf(): void
    {
        $reader = Source::fromString("ab\ncd\r\nef")->reader();

        $reader->next();
        self::assertSame('ab', $reader->text());
        self::assertSame(1, $reader->terminatorLength(), 'bare LF');

        $reader->next();
        self::assertSame('cd', $reader->text());
        self::assertSame(2, $reader->terminatorLength(), 'CRLF');

        $reader->next();
        self::assertSame('ef', $reader->text());
        self::assertSame(0, $reader->terminatorLength(), 'a final line with no terminator at all');
    }

    #[Test]
    public function anEmptyLineIsALineAndNotTheEnd(): void
    {
        $reader = Source::fromString("a\r\n\r\nb\r\n")->reader();

        $reader->next();
        self::assertSame('a', $reader->text());

        self::assertTrue($reader->next());
        self::assertSame('', $reader->text(), 'the blank separator is a line in its own right');
        self::assertSame(2, $reader->terminatorLength());

        self::assertTrue($reader->next());
        self::assertSame('b', $reader->text());
    }

    #[Test]
    public function peekDoesNotConsume(): void
    {
        $reader = Source::fromString("a\r\nb\r\n")->reader();
        $reader->next();

        self::assertSame('b', $reader->peek());
        self::assertSame('a', $reader->text(), 'still on the first line');
        self::assertSame(3, $reader->offset());

        $reader->next();
        self::assertSame('b', $reader->text());
    }

    #[Test]
    public function aNonSeekableStreamIsBufferedRatherThanRefused(): void
    {
        // A pipe: readable, not seekable -- the shape a socket has.
        $pipe = popen('printf "a\r\nb\r\n"', 'r');
        self::assertIsResource($pipe);

        $reader = Source::from($pipe)->reader();
        $reader->next();
        self::assertSame('a', $reader->text());
        $reader->next();
        self::assertSame('b', $reader->text());
        self::assertSame(3, $reader->lineStart(), 'and the offsets are real, because the bytes were buffered once');

        pclose($pipe);
    }
}
