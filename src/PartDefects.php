<?php

declare(strict_types=1);

namespace CoolMS\Mime;

/**
 * What was structurally wrong with a part, as facts rather than as exceptions.
 *
 * This exists because "the parser handled it" and "the parser survived it" are
 * different claims, and without a record the two produce identical output. A caller
 * building a corpus report, or deciding whether a message is worth showing a person,
 * needs to tell them apart; a caller that does not care can ignore the whole object.
 *
 * None of these stops a parse. Every one of them is something real senders emit.
 */
final readonly class PartDefects
{
    public function __construct(
        /** Header lines that were neither a field nor a continuation of one. */
        public int $malformedHeaderLines = 0,
        /** A `multipart/*` that declared no boundary, so it has no children it could have had. */
        public bool $multipartWithoutBoundary = false,
        /** A multipart whose closing `--boundary--` never arrived; the last child ends where the parent does. */
        public bool $unterminatedMultipart = false,
        /** A `Content-Transfer-Encoding` this library does not know, passed through undecoded. */
        public bool $unknownTransferEncoding = false,
        /** A multipart that declared a boundary but contained no delimiter at all. */
        public bool $noPartsFound = false,
    ) {
    }

    public function isClean(): bool
    {
        return 0 === $this->malformedHeaderLines
            && !$this->multipartWithoutBoundary
            && !$this->unterminatedMultipart
            && !$this->unknownTransferEncoding
            && !$this->noPartsFound;
    }

    /**
     * The defects present, as stable slugs -- which is what a divergence report groups
     * by, and what a test asserts on.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        if (0 !== $this->malformedHeaderLines) {
            $names[] = 'malformed-header-lines';
        }
        if ($this->multipartWithoutBoundary) {
            $names[] = 'multipart-without-boundary';
        }
        if ($this->unterminatedMultipart) {
            $names[] = 'unterminated-multipart';
        }
        if ($this->unknownTransferEncoding) {
            $names[] = 'unknown-transfer-encoding';
        }
        if ($this->noPartsFound) {
            $names[] = 'no-parts-found';
        }

        return $names;
    }
}
