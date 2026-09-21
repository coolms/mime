<?php

declare(strict_types=1);

namespace CoolMS\Mime;

use CoolMS\Mime\Charset\CharsetConverter;
use CoolMS\Mime\Charset\ConversionResult;
use CoolMS\Mime\Header\Headers;
use CoolMS\Mime\Io\RangeStreamWrapper;
use CoolMS\Mime\Io\Source;

use function array_values;
use function ceil;
use function fclose;
use function preg_match_all;
use function stream_filter_append;
use function stream_get_contents;
use function substr_count;
use function trim;

use const STREAM_FILTER_READ;

/**
 * One node of the parsed message: its headers, its children, and a way to read its
 * bytes without holding them.
 *
 * **{@see stream()} is the primitive and {@see content()} is a convenience on top of
 * it**, never the other way round. A `content()` that were the primitive would load
 * every attachment into memory to answer any question about it, which turns a
 * 200MB message into a 200MB process; with the stream first, the same message can be
 * copied to disk having never existed in memory, and `content()` stays available for
 * the small things where a copy costs nothing.
 *
 * **A part holds a RANGE, not bytes.** Parsing recorded where this part's body begins
 * and ends in the source; nothing was copied, and the tree of a large message costs
 * only its headers.
 */
final class Part
{
    /** @var list<Part> */
    private array $parts;

    public function __construct(
        private readonly Source $source,
        public readonly Headers $headers,
        public readonly ContentType $contentType,
        public readonly ContentDisposition $disposition,
        /** First byte of this part's body (after its own header block). */
        public readonly int $bodyStart,
        /** One past the last byte of this part's body. */
        public readonly int $bodyEnd,
        /** Lowercased `Content-Transfer-Encoding`, defaulting to `7bit` per RFC 2045. */
        public readonly string $transferEncoding = '7bit',
        /* Children, for a multipart or an embedded message. */
        array $parts = [],
        /** What was structurally wrong with this part, if anything. */
        public readonly PartDefects $defects = new PartDefects(),
    ) {
        $this->parts = array_values($parts);
    }

    /**
     * A stream of this part's DECODED bytes.
     *
     * The transfer decoding is a stream filter, so it happens as the caller reads
     * rather than up front. The returned stream is the caller's to close.
     *
     * @return resource
     */
    public function stream()
    {
        $stream = RangeStreamWrapper::open($this->source->stream(), $this->bodyStart, $this->bodyEnd);

        $filter = match ($this->transferEncoding) {
            'base64' => 'convert.base64-decode',
            'quoted-printable' => 'convert.quoted-printable-decode',
            // 7bit, 8bit, binary and anything unrecognised are passed through
            // unchanged, which is what RFC 2045 section 6.4 asks for: an unknown
            // encoding must be treated as application/octet-stream, not discarded.
            default => null,
        };

        if (null !== $filter) {
            @stream_filter_append($stream, $filter, STREAM_FILTER_READ);
        }

        return $stream;
    }

    /** This part's decoded bytes, read whole. Convenience; prefer {@see stream()} for anything large. */
    public function content(): string
    {
        $stream = $this->stream();
        $content = stream_get_contents($stream);
        fclose($stream);

        return false === $content ? '' : $content;
    }

    /**
     * This part's content as UTF-8 text, with the charset conversion reported.
     *
     * Returns the result object rather than a string because the fallback matters:
     * a caller showing a body to a person may want to know the charset was guessed.
     */
    public function text(?CharsetConverter $charsets = null): ConversionResult
    {
        return ($charsets ?? new CharsetConverter())->toUtf8($this->content(), $this->contentType->charset());
    }

    /** Exactly how many bytes this part occupies in the source, before decoding. */
    public function encodedSizeBytes(): int
    {
        return $this->bodyEnd - $this->bodyStart;
    }

    /**
     * How many bytes this part will be once decoded, WITHOUT decoding it -- or null
     * when that cannot be known without doing the work.
     *
     * The point is refusing before reading. base64 is exactly computable from its
     * length and padding; the identity encodings are their own length; and
     * quoted-printable is not computable at all without scanning, so it answers null
     * rather than a guess a caller might act on.
     */
    public function sizeBytes(): ?int
    {
        return match ($this->transferEncoding) {
            'base64' => $this->decodedBase64Size(),
            '7bit', '8bit', 'binary', '' => $this->encodedSizeBytes(),
            default => null,
        };
    }

    /**
     * @return list<Part>
     */
    public function parts(): array
    {
        return $this->parts;
    }

    public function isMultipart(): bool
    {
        return $this->contentType->isMultipart();
    }

    public function isEmbeddedMessage(): bool
    {
        return $this->contentType->isEmbeddedMessage();
    }

    /** True for a part with no children -- the ones that actually carry content. */
    public function isLeaf(): bool
    {
        return [] === $this->parts;
    }

    public function contentId(): string
    {
        return trim($this->headers->value('content-id'), '<> ');
    }

    /**
     * The name this part should be saved under, or '' when it has none.
     *
     * `Content-Disposition`'s `filename` first, because that is what RFC 2183 is for;
     * then `Content-Type`'s `name`, which predates it and which plenty of senders
     * still use alone.
     */
    public function filename(): string
    {
        $filename = $this->disposition->filename();

        return '' !== $filename ? $filename : $this->contentType->parameter('name');
    }

    /**
     * Whether a person would call this an attachment.
     *
     * Deliberately NOT just `disposition === attachment`. A part marked `inline` with
     * a filename and no `cid:` referring to it is an attachment in every mail client
     * anyone has used, and a part with no disposition at all but a `name` on its
     * content type is the shape older senders produce. What is excluded is the thing
     * every reader agrees is not an attachment: a `multipart/*` container, and the
     * text bodies of the message itself.
     */
    public function isAttachment(): bool
    {
        if ($this->isMultipart()) {
            return false;
        }
        if ($this->disposition->isAttachment()) {
            return true;
        }
        if ('' !== $this->filename()) {
            return true;
        }

        return $this->isEmbeddedMessage();
    }

    /**
     * Whether this part is one of the message's text bodies rather than content
     * hanging off it.
     */
    public function isBodyText(): bool
    {
        return $this->contentType->isText()
            && !$this->disposition->isAttachment()
            && '' === $this->filename();
    }

    private function decodedBase64Size(): int
    {
        // Count only the base64 alphabet: line breaks and stray whitespace are not
        // data, and counting them would over-report by the number of lines.
        $body = $this->source->readRange($this->bodyStart, $this->encodedSizeBytes());
        $characters = preg_match_all('/[A-Za-z0-9+\/=]/', $body);
        if (false === $characters || 0 === $characters) {
            return 0;
        }

        $padding = substr_count($body, '=');

        return (int) (ceil($characters / 4) * 3) - $padding;
    }
}
