<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Unit\Header;

use CoolMS\Mime\Header\HeaderScanner;
use CoolMS\Mime\Header\HeaderScanResult;
use CoolMS\Mime\Io\Source;
use CoolMS\Mime\Tests\Conformance\Conformance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The header block, including the shapes real mail actually arrives in.
 *
 * Several of these are the malformed corpus: a header with no colon, a continuation
 * with nothing to continue, 8-bit bytes where the RFC says there are none, a block
 * that ends at EOF. Each must parse into something defensible AND be reported as
 * malformed, because "handled" and "survived" are different claims.
 */
final class HeaderScannerTest extends TestCase
{
    #[Conformance('rfc5322-header-fields')]
    #[Test]
    public function itReadsFieldsAndSaysWhereTheBodyBegins(): void
    {
        $raw = "From: a@b.test\r\nSubject: hello\r\n\r\nbody text\r\n";
        $result = $this->scan($raw);

        self::assertSame(2, $result->headers->count());
        self::assertSame('a@b.test', $result->headers->value('from'));
        self::assertSame('hello', $result->headers->value('SUBJECT'), 'lookup is case-insensitive');
        self::assertTrue($result->terminatedByBlankLine);
        self::assertSame('body text' . "\r\n", substr($raw, $result->bodyStart));
    }

    /**
     * RFC 5322 unfolding removes the line break and KEEPS the whitespace. Joining the
     * two halves without it would turn "long subject" into "longsubject", which is
     * the classic way a folded header is silently corrupted.
     */
    #[Conformance('rfc5322-folding')]
    #[Test]
    public function aFoldedFieldIsUnfoldedWithTheWhitespaceKept(): void
    {
        $result = $this->scan("Subject: a very\r\n long subject\r\n\tand more\r\n\r\n");

        self::assertSame('a very long subject and more', $result->headers->value('subject'));
    }

    #[Conformance('rfc5322-duplicate-fields')]
    #[Test]
    public function repeatedFieldsAreAllKeptAndTheFirstAnswersASingularRead(): void
    {
        $result = $this->scan("Received: one\r\nReceived: two\r\nReceived: three\r\n\r\n");

        self::assertCount(3, $result->headers->all('received'));
        self::assertSame('one', $result->headers->value('received'), 'the first is what "the" field means');
    }

    /** A block that runs to EOF is all headers, with no body -- not a parse failure. */
    #[Test]
    public function aBlockThatEndsAtEofIsStillAWholeBlock(): void
    {
        $result = $this->scan("From: a@b.test\r\nSubject: no body follows\r\n");

        self::assertSame(2, $result->headers->count());
        self::assertFalse($result->terminatedByBlankLine);
        self::assertTrue($result->isWellFormed(), 'ending at EOF is not malformity, it is just short');
    }

    #[Conformance('rfc5322-malformed-lines')]
    #[Test]
    public function aLineWithNoColonIsCountedRatherThanInvented(): void
    {
        $result = $this->scan("From: a@b.test\r\nthis line has no colon\r\nSubject: hello\r\n\r\n");

        self::assertSame(2, $result->headers->count(), 'the junk line became no field');
        self::assertSame(1, $result->malformedLines);
        self::assertFalse($result->isWellFormed());
        self::assertSame('hello', $result->headers->value('subject'), 'and the field after it still parsed');
    }

    /** A continuation before any field has nothing to continue. */
    #[Conformance('rfc5322-malformed-lines')]
    #[Test]
    public function aContinuationWithNothingToContinueIsCounted(): void
    {
        $result = $this->scan("   orphaned continuation\r\nFrom: a@b.test\r\n\r\n");

        self::assertSame(1, $result->headers->count());
        self::assertSame(1, $result->malformedLines);
    }

    /**
     * RFC 5322 says header bytes are ASCII; RFC 6532 allows UTF-8; plenty of senders
     * emit neither and just put raw 8-bit bytes in. The scanner's job is to hand them
     * on intact -- deciding what they mean is the charset layer's problem, and
     * dropping them here would destroy the evidence.
     */
    #[Conformance('rfc6532-utf8-headers')]
    #[Test]
    public function eightBitBytesInAHeaderArePassedThroughIntact(): void
    {
        $result = $this->scan("Subject: caf\xC3\xA9 \xFF\xFE raw\r\n\r\n");

        self::assertSame("caf\xC3\xA9 \xFF\xFE raw", $result->headers->value('subject'));
        self::assertTrue($result->isWellFormed(), 'odd bytes are not a structural fault');
    }

    #[Test]
    public function theNameKeepsItsCaseAndTheValueLosesItsLeadingSpace(): void
    {
        $result = $this->scan("MIME-Version:    1.0\r\n\r\n");
        $field = $result->headers->get('mime-version');

        self::assertNotNull($field);
        self::assertSame('MIME-Version', $field->name, 'the name is reported as written');
        self::assertSame('1.0', $field->value);
    }

    /** A colon inside the VALUE is ordinary and must not split the field again. */
    #[Test]
    public function onlyTheFirstColonSeparatesNameFromValue(): void
    {
        $result = $this->scan("Date: Wed, 17 Sep 2026 12:30:00 +0000\r\n\r\n");

        self::assertSame('Wed, 17 Sep 2026 12:30:00 +0000', $result->headers->value('date'));
    }

    private function scan(string $raw): HeaderScanResult
    {
        return new HeaderScanner()->scan(Source::fromString($raw)->reader());
    }
}
