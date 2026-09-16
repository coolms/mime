<?php

declare(strict_types=1);

namespace CoolMS\Mime;

use CoolMS\Mime\Header\ParameterDecoder;
use CoolMS\Mime\Header\ParameterScanner;

use function explode;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * A parsed `Content-Type`: type, subtype and decoded parameters.
 *
 * **A part with no `Content-Type` is `text/plain; charset=us-ascii`**, which RFC 2045
 * section 5.2 states and which matters more than it sounds: it is what makes a bare
 * RFC 822 message with no MIME headers at all parse into a text part rather than into
 * nothing. The one exception the RFC also names is honoured -- inside a
 * `multipart/digest` a part with no type is `message/rfc822`.
 */
final readonly class ContentType
{
    public const string DEFAULT_TYPE = 'text';

    public const string DEFAULT_SUBTYPE = 'plain';

    /**
     * @param array<string, string> $parameters lowercased names, decoded UTF-8 values
     */
    public function __construct(
        public string $type,
        public string $subtype,
        public array $parameters = [],
    ) {
    }

    public static function parse(
        string $header,
        ?ParameterScanner $scanner = null,
        ?ParameterDecoder $decoder = null,
    ): self {
        $scanner ??= new ParameterScanner();
        $decoder ??= new ParameterDecoder();

        $scanned = $scanner->scan($header);
        $parts = explode('/', strtolower(trim($scanned['value'])), 2);

        $type = trim($parts[0]);
        $subtype = trim($parts[1] ?? '');

        // A Content-Type with no slash says nothing usable. The default is a better
        // answer than a type called "texthtml", and it is what the RFC prescribes for
        // an unparseable field.
        if ('' === $type || '' === $subtype) {
            return new self(self::DEFAULT_TYPE, self::DEFAULT_SUBTYPE, $decoder->decode($scanned['parameters']));
        }

        return new self($type, $subtype, $decoder->decode($scanned['parameters']));
    }

    /** The default for a part that declares no `Content-Type` at all. */
    public static function default(bool $insideDigest = false): self
    {
        return $insideDigest
            ? new self('message', 'rfc822')
            : new self(self::DEFAULT_TYPE, self::DEFAULT_SUBTYPE, ['charset' => 'us-ascii']);
    }

    public function full(): string
    {
        return $this->type . '/' . $this->subtype;
    }

    public function parameter(string $name, string $default = ''): string
    {
        return $this->parameters[strtolower($name)] ?? $default;
    }

    public function is(string $type, ?string $subtype = null): bool
    {
        return $this->type === strtolower($type)
            && (null === $subtype || $this->subtype === strtolower($subtype));
    }

    public function isMultipart(): bool
    {
        return 'multipart' === $this->type;
    }

    public function isText(): bool
    {
        return 'text' === $this->type;
    }

    /**
     * Whether the body of this part is itself a whole message.
     *
     * `message/global` is the RFC 6532 internationalised counterpart of
     * `message/rfc822` and parses identically -- the difference is that its headers
     * may be UTF-8 rather than encoded-words, which the header layer already handles
     * for every part.
     */
    public function isEmbeddedMessage(): bool
    {
        return 'message' === $this->type
            && ('rfc822' === $this->subtype || 'global' === $this->subtype || 'news' === $this->subtype);
    }

    /** The declared charset, or '' when none was declared. */
    public function charset(): string
    {
        return $this->parameter('charset');
    }

    /** The multipart boundary, or '' when this is not a multipart or declared none. */
    public function boundary(): string
    {
        return $this->parameter('boundary');
    }

    /** Whether a `Content-Type` header was a `multipart/*` that forgot its boundary. */
    public function isUnbounded(): bool
    {
        return $this->isMultipart() && '' === $this->boundary();
    }
}
