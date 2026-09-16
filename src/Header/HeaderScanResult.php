<?php

declare(strict_types=1);

namespace CoolMS\Mime\Header;

/**
 * What scanning a header block produced: the fields, where the body starts, and what
 * was wrong with the block.
 *
 * The last two exist so malformity is a REPORTED FACT rather than a silent recovery.
 * A parser that quietly skips a line with no colon and a parser that never met one
 * produce identical output, and the difference matters to whoever is deciding whether
 * a corpus message is being handled or merely survived.
 */
final readonly class HeaderScanResult
{
    public function __construct(
        public Headers $headers,
        /** Byte offset of the first byte of the body. */
        public int $bodyStart,
        /** Lines that were neither a field nor a continuation of one. */
        public int $malformedLines,
        /**
         * Whether a blank line actually ended the block. False means the source ran
         * out first, which is a different shape: there is no body, as against a body
         * that happens to be empty.
         */
        public bool $terminatedByBlankLine,
    ) {
    }

    public function isWellFormed(): bool
    {
        return 0 === $this->malformedLines;
    }
}
