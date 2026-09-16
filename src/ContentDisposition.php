<?php

declare(strict_types=1);

namespace CoolMS\Mime;

use CoolMS\Mime\Header\ParameterDecoder;
use CoolMS\Mime\Header\ParameterScanner;

use function strtolower;
use function trim;

/**
 * A parsed `Content-Disposition` (RFC 2183): how a part is meant to be presented, and
 * under what name.
 *
 * **The disposition alone does not decide what is an attachment.** A part with no
 * `Content-Disposition` at all but a `filename` on its `Content-Type`, and a part
 * marked `inline` that carries a filename and is referenced by no `cid:` anywhere,
 * are both attachments to every mail client a person has used. That judgement needs
 * the whole part and so it lives on {@see Part}; this class only reports what the
 * header said.
 */
final readonly class ContentDisposition
{
    public const string ATTACHMENT = 'attachment';

    public const string INLINE = 'inline';

    /**
     * @param array<string, string> $parameters lowercased names, decoded UTF-8 values
     */
    public function __construct(
        /** `attachment`, `inline`, or whatever else the sender wrote, lowercased. '' when absent. */
        public string $disposition,
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

        return new self(strtolower(trim($scanned['value'])), $decoder->decode($scanned['parameters']));
    }

    public static function absent(): self
    {
        return new self('');
    }

    public function parameter(string $name, string $default = ''): string
    {
        return $this->parameters[strtolower($name)] ?? $default;
    }

    public function filename(): string
    {
        return $this->parameter('filename');
    }

    public function isAttachment(): bool
    {
        return self::ATTACHMENT === $this->disposition;
    }

    public function isInline(): bool
    {
        return self::INLINE === $this->disposition;
    }

    public function isAbsent(): bool
    {
        return '' === $this->disposition;
    }
}
