<?php

declare(strict_types=1);

namespace CoolMS\Mime\Charset;

/**
 * Text converted to UTF-8, and the truth about how.
 *
 * The second field is the point. A converter that silently falls back turns "this
 * message declared an encoding nobody has" into "here is some text", and the reader
 * of that text has no way to know it is looking at a guess. So the charset actually
 * used comes back with the text, and {@see $wasFallback} says whether it was the one
 * the message asked for.
 */
final readonly class ConversionResult
{
    public function __construct(
        public string $text,
        /** The charset the bytes were actually decoded as. */
        public string $charsetUsed,
        /** The charset the message declared, empty when it declared none. */
        public string $declared,
        /** True when `$charsetUsed` is not what `$declared` asked for. */
        public bool $wasFallback,
    ) {
    }
}
