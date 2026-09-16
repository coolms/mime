<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Unit\Header;

use CoolMS\Mime\Header\EncodedWordDecoder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RFC 2047, including the rule that makes a pattern the wrong tool.
 */
final class EncodedWordDecoderTest extends TestCase
{
    private EncodedWordDecoder $decoder;

    #[Test]
    public function itDecodesBase64AndQuotedPrintableWords(): void
    {
        self::assertSame('Grüße', $this->decoder->decode('=?utf-8?B?R3LDvMOfZQ==?='));
        self::assertSame('Grüße', $this->decoder->decode('=?utf-8?Q?Gr=C3=BC=C3=9Fe?='));
    }

    /**
     * RFC 2047 section 4.2: in Q encoding `_` is a SPACE. It is not the
     * quoted-printable rule, which leaves underscores alone, and a decoder that
     * reuses quoted-printable unmodified turns every space in a subject into an
     * underscore.
     */
    #[Test]
    public function underscoreIsASpaceInQEncoding(): void
    {
        self::assertSame('hello there', $this->decoder->decode('=?utf-8?Q?hello_there?='));
    }

    /**
     * THE adjacency rule (section 6.2), and the reason this is a scanner. Whitespace
     * BETWEEN two encoded-words is not text; whitespace between a word and ordinary
     * text is. Both appear in one line here, so an implementation that picks either
     * rule globally fails.
     */
    #[Test]
    public function whitespaceBetweenTwoEncodedWordsIsDroppedAndWhitespaceBesideTextIsKept(): void
    {
        self::assertSame(
            'Grüße',
            $this->decoder->decode('=?utf-8?Q?Gr=C3=BC?= =?utf-8?Q?=C3=9Fe?='),
            'the space between the halves of one word is not part of it',
        );

        self::assertSame(
            'Re: Grüße from me',
            $this->decoder->decode('Re: =?utf-8?Q?Gr=C3=BC?= =?utf-8?Q?=C3=9Fe?= from me'),
            'but the spaces against the surrounding text survive',
        );
    }

    /** A fold puts the two halves on separate lines; unfolding leaves exactly this. */
    #[Test]
    public function aWordSplitAcrossAFoldStillJoins(): void
    {
        // What HeaderScanner produces from "=?utf-8?Q?Gr=C3=BC?=\r\n =?utf-8?Q?=C3=9Fe?="
        self::assertSame('Grüße', $this->decoder->decode('=?utf-8?Q?Gr=C3=BC?= =?utf-8?Q?=C3=9Fe?='));
    }

    #[Test]
    public function textWithNoEncodedWordsIsUntouched(): void
    {
        self::assertSame('a plain subject', $this->decoder->decode('a plain subject'));
        self::assertSame('  leading and trailing  ', $this->decoder->decode('  leading and trailing  '));
    }

    /**
     * Broken words are left EXACTLY as written. The alternative is to throw away
     * bytes the sender meant or to show a mangled guess with nothing marking it as
     * one.
     */
    #[Test]
    public function aMalformedWordIsLeftLiteral(): void
    {
        $cases = [
            '=?utf-8?X?not-an-encoding?=' => 'an unknown encoding letter',
            '=?utf-8?B?!!!not base64!!!?=' => 'base64 that will not decode',
            '=?utf-8?Q?unterminated' => 'no terminator at all',
            '=?utf-8?' => 'truncated mid-word',
        ];

        foreach ($cases as $input => $why) {
            self::assertSame($input, $this->decoder->decode($input), $why);
        }
    }

    /** RFC 2231 section 5 allows a language tag on the charset; it is not part of the charset name. */
    #[Test]
    public function aLanguageSuffixOnTheCharsetIsIgnoredRatherThanBreakingTheWord(): void
    {
        self::assertSame('Grüße', $this->decoder->decode('=?utf-8*de?Q?Gr=C3=BC=C3=9Fe?='));
    }

    #[Test]
    public function aNonUtf8CharsetIsConvertedToUtf8(): void
    {
        // "Grüße" in ISO-8859-1.
        self::assertSame('Grüße', $this->decoder->decode("=?iso-8859-1?Q?Gr=FC=DFe?="));
    }

    #[Test]
    public function encodedWordsMixedWithTextKeepTheirOrder(): void
    {
        self::assertSame(
            'start ü middle ß end',
            $this->decoder->decode('start =?utf-8?Q?=C3=BC?= middle =?utf-8?Q?=C3=9F?= end'),
        );
    }

    protected function setUp(): void
    {
        $this->decoder = new EncodedWordDecoder();
    }
}
