<?php

declare(strict_types=1);

namespace CoolMS\Mime\Header;

use CoolMS\Mime\Io\LineReader;

use function ltrim;
use function rtrim;
use function str_contains;
use function strpos;
use function substr;

/**
 * Turns the lines at the head of a part into header fields, and says where the body
 * begins.
 *
 * A scanner rather than an expression. The rules are sequential and stateful --
 * a line beginning with whitespace belongs to the field before it, a blank line ends
 * the block, a line with no colon belongs to nothing -- and that is what a scanner
 * expresses directly. A pattern over the whole block has to encode "the previous
 * line" as lookbehind and gets the folding wrong the first time a value legitimately
 * contains a colon or a blank-looking line.
 *
 * **What it tolerates, because real mail does it.** A header block that ends at EOF
 * with no blank line (the message is all headers). A line with no colon, which is
 * counted and skipped rather than invented into a field with an empty name. A
 * continuation line before any field has been seen, which has nothing to continue and
 * is likewise counted. Each is reported on {@see HeaderScanResult} so a caller can
 * know the message was malformed without the parse having failed.
 */
final readonly class HeaderScanner
{
    /**
     * Scan from the reader's current position to the end of the header block.
     *
     * The reader is left positioned at the first byte of the body.
     */
    public function scan(LineReader $reader): HeaderScanResult
    {
        /** @var list<HeaderField> $fields */
        $fields = [];
        $name = null;
        $value = '';
        $malformedLines = 0;
        $bodyStart = $reader->offset();

        while ($reader->next()) {
            $line = $reader->text();

            // A blank line ends the block. The body starts after it -- after its
            // terminator, which is not part of either the headers or the body.
            if ('' === $line) {
                $bodyStart = $reader->offset();
                if (null !== $name) {
                    $fields[] = new HeaderField($name, $value);
                }

                return new HeaderScanResult(new Headers($fields), $bodyStart, $malformedLines, true);
            }

            // A line starting with space or tab continues the field before it. RFC
            // 5322 unfolding removes the line break and KEEPS the whitespace: that
            // whitespace separates two words that were never one.
            if (' ' === $line[0] || "\t" === $line[0]) {
                if (null === $name) {
                    ++$malformedLines;

                    continue;
                }
                $value .= ' ' . ltrim($line);

                continue;
            }

            if (!str_contains($line, ':')) {
                // Not a field, not a continuation. Counted, not guessed at.
                ++$malformedLines;

                continue;
            }

            if (null !== $name) {
                $fields[] = new HeaderField($name, $value);
            }

            $colon = strpos($line, ':');
            $name = substr($line, 0, $colon) |> rtrim(...);
            $value = substr($line, $colon + 1) |> ltrim(...);
            $bodyStart = $reader->offset();
        }

        // EOF without a blank line: the whole source was headers, and the body is
        // empty rather than missing.
        if (null !== $name) {
            $fields[] = new HeaderField($name, $value);
        }

        return new HeaderScanResult(new Headers($fields), $reader->offset(), $malformedLines, false);
    }
}
