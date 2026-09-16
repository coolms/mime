<?php

declare(strict_types=1);

namespace CoolMS\Mime\Header;

use function strtolower;

/**
 * One header field, as it appeared: the name exactly as written, and the value with
 * its folding undone and nothing else done to it.
 *
 * Decoding is deliberately NOT applied here. An encoded-word means something in a
 * `Subject` and nothing in a `Content-Type` boundary parameter, RFC 2231 continuations
 * span several parameters of one field, and a caller reading `Received` wants the
 * bytes that arrived. So this holds the raw value and the decoders are asked for
 * explicitly by whoever knows which rules apply.
 */
final readonly class HeaderField
{
    public function __construct(
        /** The field name exactly as it appeared, case and all. */
        public string $name,
        /**
         * The value with folding undone per RFC 5322 section 2.2.3: the CRLF of a
         * fold is removed and the whitespace that followed it is KEPT, because that
         * whitespace is part of the value and removing it joins words that were
         * separate.
         */
        public string $value,
    ) {
    }

    /** Lowercased name, which is how every lookup in this library compares. */
    public function key(): string
    {
        return strtolower($this->name);
    }
}
