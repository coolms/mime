<?php

declare(strict_types=1);

namespace CoolMS\Mime;

use CoolMS\Mime\Header\HeaderScanner;
use CoolMS\Mime\Header\ParameterDecoder;
use CoolMS\Mime\Header\ParameterScanner;
use CoolMS\Mime\Io\LineReader;
use CoolMS\Mime\Io\Source;

use function in_array;
use function min;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Parses a message into a tree of {@see Part}s, reading each level once and copying
 * nothing.
 *
 * **Recursive descent over RANGES, not one pass with a boundary stack.** A multipart
 * scans only for its OWN delimiter and hands each child the byte range between two of
 * them; the child then scans that range for its own. The two shapes are equivalent on
 * well-formed input and differ on the input that matters: a nested multipart whose
 * closing delimiter never arrives ends where its PARENT's next delimiter is, which is
 * both the RFC's recovery and the only answer that keeps the rest of the message
 * readable. A single stack-walking pass has to decide the same thing with more state
 * and gets it wrong more interestingly.
 *
 * **The break before a delimiter belongs to the delimiter** (RFC 2046 section 5.1.1),
 * not to the body above it. So a part's body ends where its last content line ends,
 * not where that line's terminator does -- which is a one-or-two byte difference that
 * changes every base64 attachment's decoded bytes if it is got wrong.
 */
final readonly class MessageParser
{
    public function __construct(
        private HeaderScanner $headers = new HeaderScanner(),
        private ParameterScanner $parameters = new ParameterScanner(),
        private ParameterDecoder $decoder = new ParameterDecoder(),
    ) {
    }

    /**
     * @param resource|string $raw
     */
    public function parse(mixed $raw): Message
    {
        $source = Source::from($raw);

        return new Message($this->parsePart($source, 0, $source->size(), false));
    }

    private function parsePart(Source $source, int $start, int $end, bool $insideDigest): Part
    {
        $reader = new LineReader($source->stream(), $start, $end);
        $scan = $this->headers->scan($reader);

        $contentType = $scan->headers->has('content-type')
            ? ContentType::parse($scan->headers->value('content-type'), $this->parameters, $this->decoder)
            : ContentType::default($insideDigest);

        $disposition = $scan->headers->has('content-disposition')
            ? ContentDisposition::parse($scan->headers->value('content-disposition'), $this->parameters, $this->decoder)
            : ContentDisposition::absent();

        $encoding = $scan->headers->value('content-transfer-encoding', '7bit') |> trim(...) |> strtolower(...);
        $known = ['7bit', '8bit', 'binary', 'base64', 'quoted-printable', ''];

        // A header block that ran to the end of this part's range leaves no body.
        // Clamping keeps the range from inverting, which would make every size
        // negative and every stream nonsense -- a shape a part whose headers are
        // never closed by a blank line can otherwise reach.
        $bodyStart = min($scan->bodyStart, $end);
        $bodyEnd = $end;

        $children = [];
        $unterminated = false;
        $noParts = false;

        if ($contentType->isMultipart() && !$contentType->isUnbounded()) {
            $walk = $this->walkMultipart($source, $contentType, $bodyStart, $bodyEnd);
            $children = $walk['parts'];
            $unterminated = $walk['unterminated'];
            $noParts = [] === $children;
        } elseif ($contentType->isEmbeddedMessage()) {
            // The body IS a message. One child, the whole range.
            $children = [$this->parsePart($source, $bodyStart, $bodyEnd, false)];
        }

        return new Part(
            $source,
            $scan->headers,
            $contentType,
            $disposition,
            $bodyStart,
            $bodyEnd,
            $encoding,
            $children,
            new PartDefects(
                malformedHeaderLines: $scan->malformedLines,
                multipartWithoutBoundary: $contentType->isUnbounded(),
                unterminatedMultipart: $unterminated,
                unknownTransferEncoding: !in_array($encoding, $known, true),
                noPartsFound: $noParts,
            ),
        );
    }

    /**
     * @return array{parts: list<Part>, unterminated: bool}
     */
    private function walkMultipart(Source $source, ContentType $contentType, int $bodyStart, int $bodyEnd): array
    {
        $delimiter = '--' . trim($contentType->boundary());
        $isDigest = $contentType->is('multipart', 'digest');

        $reader = new LineReader($source->stream(), $bodyStart, $bodyEnd);
        $parts = [];
        $childStart = null;
        // Where the previous line's CONTENT ended -- not where its terminator did.
        $previousContentEnd = $bodyStart;
        $closed = false;

        while ($reader->next()) {
            $line = $reader->text();
            $lineStart = $reader->lineStart();

            $kind = $this->delimiterKind($line, $delimiter);
            if (null === $kind) {
                $previousContentEnd = $lineStart + strlen($line);

                continue;
            }

            if (null !== $childStart) {
                $parts[] = $this->parsePart($source, $childStart, $previousContentEnd, $isDigest);
            }

            if ('close' === $kind) {
                $closed = true;

                break;
            }

            // Everything before the first delimiter is the preamble, which RFC 2046
            // says to ignore. Nothing is recorded for it because nothing may act on
            // it: a reader that showed a preamble would be showing text written for
            // clients that predate MIME.
            $childStart = $reader->offset();
            $previousContentEnd = $reader->offset();
        }

        // No closing delimiter: the last child runs to the end of this multipart
        // rather than being discarded. Truncation is the commonest corruption in
        // stored mail and losing the final part to it would be gratuitous.
        if (!$closed && null !== $childStart) {
            $parts[] = $this->parsePart($source, $childStart, $bodyEnd, $isDigest);
        }

        return ['parts' => $parts, 'unterminated' => !$closed && [] !== $parts];
    }

    /**
     * Whether this line is a delimiter for `$delimiter`, and which kind.
     *
     * A line that merely BEGINS with the delimiter is not one: `--frontier` and
     * `--frontierX` are different boundaries, and treating the second as the first
     * splits a part in the middle. RFC 2046 does allow trailing whitespace after a
     * delimiter, and senders emit it, so that is tolerated and nothing else is.
     *
     * @return 'open'|'close'|null
     */
    private function delimiterKind(string $line, string $delimiter): ?string
    {
        if (!str_starts_with($line, $delimiter)) {
            return null;
        }

        $rest = substr($line, strlen($delimiter));

        if (str_starts_with($rest, '--')) {
            return '' === trim(substr($rest, 2)) ? 'close' : null;
        }

        return '' === trim($rest) ? 'open' : null;
    }
}
