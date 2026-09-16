<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Unit\Header;

use CoolMS\Mime\ContentDisposition;
use CoolMS\Mime\ContentType;
use CoolMS\Mime\Header\ParameterScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Structured header parameters: the quote-aware split, and RFC 2231 reassembly.
 *
 * Both halves are places where a pattern is wrong on real input rather than on
 * contrived input -- a boundary containing a semicolon is legal and generators emit
 * one, and continuation segments do arrive out of order.
 */
final class ParameterTest extends TestCase
{
    /** A semicolon inside a quoted value is not a separator. */
    #[Test]
    public function aQuotedValueMayContainTheSeparator(): void
    {
        $type = ContentType::parse('multipart/mixed; boundary="a;b"; charset=utf-8');

        self::assertSame('multipart/mixed', $type->full());
        self::assertSame('a;b', $type->boundary());
        self::assertSame('utf-8', $type->charset());
    }

    #[Test]
    public function escapedQuotesInsideAValueSurvive(): void
    {
        $disposition = ContentDisposition::parse('attachment; filename="say \\"hi\\".txt"');

        self::assertSame('say "hi".txt', $disposition->filename());
    }

    #[Test]
    public function anUnterminatedQuotedValueRunsToTheEndRatherThanVanishing(): void
    {
        $disposition = ContentDisposition::parse('attachment; filename="unterminated.txt');

        self::assertSame('unterminated.txt', $disposition->filename());
    }

    #[Test]
    public function aParameterWithNoValueIsKeptRatherThanDropped(): void
    {
        $scanned = new ParameterScanner()->scan('attachment; filename');

        self::assertSame('attachment', $scanned['value']);
        self::assertSame('filename', $scanned['parameters'][0]['name']);
        self::assertSame('', $scanned['parameters'][0]['value']);
    }

    // -- RFC 2231 -------------------------------------------------------------

    #[Test]
    public function aContinuationIsConcatenatedByIndex(): void
    {
        $disposition = ContentDisposition::parse(
            'attachment; filename*0="a long "; filename*1="file name.txt"',
        );

        self::assertSame('a long file name.txt', $disposition->filename());
    }

    /**
     * Segments arriving out of order. Concatenating as they arrive produces a name
     * with its halves swapped, which is worse than failing because it looks plausible.
     */
    #[Test]
    public function segmentsAreSortedByIndexNotByArrival(): void
    {
        $disposition = ContentDisposition::parse(
            'attachment; filename*1="second.txt"; filename*0="first-"',
        );

        self::assertSame('first-second.txt', $disposition->filename());
    }

    #[Test]
    public function anExtendedValueCarriesItsOwnCharset(): void
    {
        $disposition = ContentDisposition::parse(
            "attachment; filename*=utf-8'en'%C3%BCber.txt",
        );

        self::assertSame('über.txt', $disposition->filename());
    }

    #[Test]
    public function aContinuationOfExtendedSegmentsTakesTheCharsetFromSegmentZero(): void
    {
        $disposition = ContentDisposition::parse(
            "attachment; filename*0*=utf-8''%C3%BC; filename*1*=ber.txt",
        );

        self::assertSame('über.txt', $disposition->filename());
    }

    /** A non-UTF-8 extended value converts like any other declared charset. */
    #[Test]
    public function anExtendedValueInAnotherCharsetIsConverted(): void
    {
        $disposition = ContentDisposition::parse(
            "attachment; filename*=iso-8859-1''%FCber.txt",
        );

        self::assertSame('über.txt', $disposition->filename());
    }

    /**
     * Not RFC 2231 at all, and everywhere: an encoded-word in a plain filename
     * parameter. Refusing to decode it shows a person `=?utf-8?B?...?=` as the name
     * of their attachment.
     */
    #[Test]
    public function anEncodedWordInAPlainFilenameIsDecodedAnyway(): void
    {
        $disposition = ContentDisposition::parse(
            'attachment; filename="=?utf-8?B?w7xiZXIudHh0?="',
        );

        self::assertSame('über.txt', $disposition->filename());
    }

    /** A sender that emits both means the old reader to take the plain one and a new reader the starred one. */
    #[Test]
    public function aStarredParameterWinsOverItsPlainTwin(): void
    {
        $disposition = ContentDisposition::parse(
            "attachment; filename=\"uber.txt\"; filename*=utf-8''%C3%BCber.txt",
        );

        self::assertSame('über.txt', $disposition->filename());
    }

    #[Test]
    public function parameterNamesAreCaseInsensitive(): void
    {
        $type = ContentType::parse('TEXT/PLAIN; CHARSET=UTF-8');

        self::assertSame('text/plain', $type->full());
        self::assertSame('UTF-8', $type->charset(), 'the VALUE keeps its case; only the name is folded');
    }

    // -- content type defaults ------------------------------------------------

    #[Test]
    public function aContentTypeWithNoSlashFallsBackToTheDefault(): void
    {
        $type = ContentType::parse('texthtml; charset=utf-8');

        self::assertSame('text/plain', $type->full(), 'a better answer than a type called texthtml');
        self::assertSame('utf-8', $type->charset(), 'and the parameters are still read');
    }

    #[Test]
    public function messageGlobalIsAnEmbeddedMessageJustAsRfc822Is(): void
    {
        self::assertTrue(ContentType::parse('message/rfc822')->isEmbeddedMessage());
        self::assertTrue(ContentType::parse('message/global')->isEmbeddedMessage());
        self::assertFalse(ContentType::parse('message/delivery-status')->isEmbeddedMessage());
    }
}
