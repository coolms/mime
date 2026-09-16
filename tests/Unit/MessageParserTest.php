<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Unit;

use CoolMS\Mime\Message;
use CoolMS\Mime\MessageParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function implode;
use function str_repeat;

/**
 * The part tree.
 *
 * Several of these are the malformed corpus rather than the happy path, and they are
 * the reason the walk is shaped the way it is: an unterminated multipart, a
 * boundary-looking line that is not one, a multipart that forgot its boundary, a
 * nested multipart reusing its parent's.
 */
final class MessageParserTest extends TestCase
{
    private MessageParser $parser;

    /** RFC 2045 section 5.2: no Content-Type at all means text/plain, not nothing. */
    #[Test]
    public function aBareRfc822MessageIsOneTextPart(): void
    {
        $message = $this->parse([
            'From: a@b.test',
            'Subject: plain',
            '',
            'the body',
            '',
        ]);

        self::assertSame('text/plain', $message->root->contentType->full());
        self::assertTrue($message->root->isLeaf());
        self::assertSame("the body\r\n", $message->root->content());
        self::assertSame('plain', $message->subject());
    }

    #[Test]
    public function aMultipartSplitsIntoItsParts(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/alternative; boundary="frontier"',
            '',
            'this preamble is ignored',
            '--frontier',
            'Content-Type: text/plain',
            '',
            'the plain body',
            '--frontier',
            'Content-Type: text/html',
            '',
            '<p>the html body</p>',
            '--frontier--',
            'this epilogue is ignored too',
            '',
        ]);

        $parts = $message->root->parts();
        self::assertCount(2, $parts);
        self::assertSame('text/plain', $parts[0]->contentType->full());
        self::assertSame('the plain body', $parts[0]->content());
        self::assertSame('text/html', $parts[1]->contentType->full());
        self::assertSame('<p>the html body</p>', $parts[1]->content());
    }

    /**
     * RFC 2046 section 5.1.1: the line break BEFORE a delimiter belongs to the
     * delimiter, not to the body above it. One or two bytes, and getting it wrong
     * corrupts every base64 attachment in the message.
     */
    #[Test]
    public function theBreakBeforeADelimiterIsNotPartOfTheBodyAboveIt(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/mixed; boundary="b"',
            '',
            '--b',
            '',
            'exactly this',
            '--b--',
            '',
        ]);

        self::assertSame('exactly this', $message->root->parts()[0]->content(), 'no trailing CRLF');
    }

    #[Test]
    public function nestedMultipartsAreWalkedToTheirOwnDepth(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/mixed; boundary="outer"',
            '',
            '--outer',
            'Content-Type: multipart/alternative; boundary="inner"',
            '',
            '--inner',
            'Content-Type: text/plain',
            '',
            'inner plain',
            '--inner',
            'Content-Type: text/html',
            '',
            '<p>inner html</p>',
            '--inner--',
            '--outer',
            'Content-Type: text/plain',
            'Content-Disposition: attachment; filename="note.txt"',
            '',
            'attached',
            '--outer--',
            '',
        ]);

        $top = $message->root->parts();
        self::assertCount(2, $top);
        self::assertTrue($top[0]->isMultipart());
        self::assertCount(2, $top[0]->parts());
        self::assertSame('inner plain', $top[0]->parts()[0]->content());
        self::assertSame('note.txt', $top[1]->filename());
        self::assertCount(1, $message->attachments());
    }

    /**
     * A line that merely BEGINS with the boundary is not a delimiter. `--frontierX`
     * is a different boundary, and treating it as one splits a part in half.
     */
    #[Test]
    public function aLineThatMerelyStartsWithTheBoundaryIsNotADelimiter(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/mixed; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: text/plain',
            '',
            'first line',
            '--frontierX is body text, not a boundary',
            'last line',
            '--frontier--',
            '',
        ]);

        self::assertCount(1, $message->root->parts());
        self::assertStringContainsString('--frontierX is body text', $message->root->parts()[0]->content());
    }

    /** Trailing whitespace after a delimiter is allowed by the RFC and senders emit it. */
    #[Test]
    public function trailingWhitespaceAfterADelimiterIsTolerated(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/mixed; boundary="b"',
            '',
            '--b  ',
            '',
            'body',
            '--b--  ',
            '',
        ]);

        self::assertCount(1, $message->root->parts());
        self::assertSame('body', $message->root->parts()[0]->content());
    }

    // -- the malformed corpus -------------------------------------------------

    /**
     * Truncation is the commonest corruption in stored mail. The last part runs to
     * the end of the multipart rather than being discarded, and the defect is
     * RECORDED so a caller can tell a recovered message from an intact one.
     */
    #[Test]
    public function anUnterminatedMultipartKeepsItsLastPartAndSaysItWasUnterminated(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/mixed; boundary="b"',
            '',
            '--b',
            'Content-Type: text/plain',
            '',
            'this part never closes',
        ]);

        self::assertCount(1, $message->root->parts());
        self::assertStringContainsString('this part never closes', $message->root->parts()[0]->content());
        self::assertContains('unterminated-multipart', $message->defects());
    }

    #[Test]
    public function aMultipartWithNoBoundaryIsRecordedRatherThanCrashing(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/mixed',
            '',
            'there is no boundary so there are no parts',
            '',
        ]);

        self::assertSame([], $message->root->parts());
        self::assertContains('multipart-without-boundary', $message->defects());
    }

    #[Test]
    public function aMultipartWhoseBoundaryNeverAppearsIsRecorded(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/mixed; boundary="nowhere"',
            '',
            'no delimiter in sight',
            '',
        ]);

        self::assertSame([], $message->root->parts());
        self::assertContains('no-parts-found', $message->defects());
    }

    /**
     * A nested multipart reusing its parent's boundary. Every parser splits at the
     * parent's delimiter, because a boundary is required to be unique and there is no
     * information left to do otherwise. What matters is that the rest of the message
     * still parses rather than the walk losing its place.
     */
    #[Test]
    public function aNestedMultipartReusingItsParentsBoundaryDoesNotDerailTheWalk(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/mixed; boundary="same"',
            '',
            '--same',
            'Content-Type: multipart/alternative; boundary="same"',
            '',
            '--same',
            'Content-Type: text/plain',
            '',
            'confused',
            '--same--',
            '',
        ]);

        // The assertion is deliberately about survival, not about a "right" tree:
        // the input is self-contradictory and the parse must simply terminate with
        // something readable.
        self::assertNotSame([], $message->allParts());
        self::assertGreaterThanOrEqual(1, count($message->root->parts()));
    }

    /**
     * A child whose header block is never closed by a blank line -- the delimiter
     * arrives first. The scan of that child must stop at the END OF ITS RANGE, or it
     * reads the next sibling's delimiter and headers as though they were its own and
     * every part after it is lost.
     *
     * This case exists because a mutation removing the reader's range limit killed
     * nothing: every other test gives each child a blank line, so the limit was never
     * the thing that stopped the scan.
     */
    #[Test]
    public function aChildWhoseHeaderBlockIsNeverClosedDoesNotSwallowItsSiblings(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/mixed; boundary="b"',
            '',
            '--b',
            'Content-Type: text/plain',
            '--b',
            'Content-Type: text/html',
            '',
            '<p>the second part survived</p>',
            '--b--',
            '',
        ]);

        $parts = $message->root->parts();
        self::assertCount(2, $parts, 'the unclosed header block must not have eaten the sibling');
        self::assertSame('text/plain', $parts[0]->contentType->full());
        self::assertSame('', $parts[0]->content(), 'it had no body, which is different from having the rest of the message');
        self::assertSame('text/html', $parts[1]->contentType->full());
        self::assertSame('<p>the second part survived</p>', $parts[1]->content());

        // The discriminating assertions. A scan that ran past the range would read
        // the sibling's delimiter as a malformed line and its Content-Type as a
        // second field of THIS part -- and both of those are invisible in the
        // content, which is why asserting the content alone proved nothing.
        self::assertSame(1, $parts[0]->headers->count(), 'exactly its own one header');
        self::assertSame(0, $parts[0]->defects->malformedHeaderLines, 'it never saw the sibling delimiter');
        self::assertSame(0, $parts[0]->encodedSizeBytes(), 'and its body range is empty rather than inverted');
    }

    #[Test]
    public function anUnknownTransferEncodingIsPassedThroughAndRecorded(): void
    {
        $message = $this->parse([
            'Content-Type: text/plain',
            'Content-Transfer-Encoding: x-uuencode-ish',
            '',
            'raw bytes',
            '',
        ]);

        self::assertSame("raw bytes\r\n", $message->root->content(), 'undecoded, not discarded');
        self::assertContains('unknown-transfer-encoding', $message->defects());
        self::assertNull($message->root->sizeBytes(), 'and its decoded size is unknowable');
    }

    // -- transfer encodings ---------------------------------------------------

    #[Test]
    public function base64IsDecodedByStreamAndItsSizeIsKnownWithoutDecoding(): void
    {
        $payload = str_repeat('the quick brown fox. ', 100);
        $message = $this->parse([
            'Content-Type: application/octet-stream',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="big.bin"',
            '',
            chunk_split(base64_encode($payload), 76, "\r\n") . '--END--',
        ]);

        // The trailing marker keeps the test honest about where the body ends.
        $part = $message->root;
        self::assertSame('big.bin', $part->filename());
        self::assertStringStartsWith($payload, $part->content());
        self::assertNotNull($part->sizeBytes());
        self::assertGreaterThan(0, $part->sizeBytes());
    }

    #[Test]
    public function quotedPrintableIsDecodedAndItsDecodedSizeIsNotGuessedAt(): void
    {
        $message = $this->parse([
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'Gr=C3=BC=C3=9Fe',
            '',
        ]);

        self::assertSame("Grüße\r\n", $message->root->content());
        self::assertNull($message->root->sizeBytes(), 'not computable without scanning, so not answered');
    }

    #[Test]
    public function aPartsStreamCanBeReadIndependentlyOfAnother(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/mixed; boundary="b"',
            '',
            '--b',
            'Content-Type: text/plain',
            '',
            'first',
            '--b',
            'Content-Type: text/plain',
            '',
            'second',
            '--b--',
            '',
        ]);

        [$one, $two] = $message->root->parts();
        $a = $one->stream();
        $b = $two->stream();

        // Interleaved reads over one shared source: the reason every read seeks.
        self::assertSame('fir', fread($a, 3));
        self::assertSame('sec', fread($b, 3));
        self::assertSame('st', fread($a, 3));
        self::assertSame('ond', fread($b, 3));

        fclose($a);
        fclose($b);
    }

    // -- embedded messages ----------------------------------------------------

    #[Test]
    public function anEmbeddedMessageIsParsedAsAMessageAndCountedWholeAsOneAttachment(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/mixed; boundary="b"',
            '',
            '--b',
            'Content-Type: text/plain',
            '',
            'see attached',
            '--b',
            'Content-Type: message/rfc822',
            '',
            'From: inner@b.test',
            'Subject: the forwarded one',
            'Content-Type: multipart/mixed; boundary="i"',
            '',
            '--i',
            'Content-Type: text/plain',
            '',
            'inner body',
            '--i',
            'Content-Type: text/plain',
            'Content-Disposition: attachment; filename="inner.txt"',
            '',
            'inner attachment',
            '--i--',
            '--b--',
            '',
        ]);

        $embedded = $message->root->parts()[1];
        self::assertTrue($embedded->isEmbeddedMessage());
        self::assertCount(1, $embedded->parts(), 'its body is one whole message');
        self::assertSame('the forwarded one', $embedded->parts()[0]->headers->value('subject'));

        // The forwarded message's own attachment is ITS attachment, not this one's.
        self::assertCount(1, $message->attachments());
        self::assertTrue($message->attachments()[0]->isEmbeddedMessage());
    }

    /** RFC 2046: inside a multipart/digest, a part with no Content-Type is message/rfc822. */
    #[Test]
    public function insideADigestTheDefaultTypeIsAnEmbeddedMessage(): void
    {
        $message = $this->parse([
            'Content-Type: multipart/digest; boundary="d"',
            '',
            '--d',
            '',
            'Subject: a whole message with no content-type',
            '',
            'body',
            '--d--',
            '',
        ]);

        self::assertSame('message/rfc822', $message->root->parts()[0]->contentType->full());
    }

    protected function setUp(): void
    {
        $this->parser = new MessageParser();
    }

    /**
     * @param list<string> $lines
     */
    private function parse(array $lines): Message
    {
        return $this->parser->parse(implode("\r\n", $lines));
    }
}
