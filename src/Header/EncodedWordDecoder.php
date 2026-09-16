<?php

declare(strict_types=1);

namespace CoolMS\Mime\Header;

use CoolMS\Mime\Charset\CharsetConverter;

use function base64_decode;
use function ctype_space;
use function quoted_printable_decode;
use function str_replace;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;

/**
 * Decodes RFC 2047 encoded-words in a header value to UTF-8 text.
 *
 * A scanner, walking the value once. The rule that makes a pattern the wrong tool is
 * the ADJACENCY rule (RFC 2047 section 6.2): whitespace BETWEEN two encoded-words is
 * not part of the text and must be dropped, while whitespace between an encoded-word
 * and ordinary text must be kept. That is a fact about the token before the current
 * one, which a scanner holds and a pattern has to fake with lookbehind -- and gets
 * wrong on the first subject line that mixes the two.
 *
 * **A malformed encoded-word is left exactly as it was written.** Unknown encoding
 * letter, base64 that will not decode, a charset nothing can convert: the literal
 * `=?...?=` text goes through untouched. That is what the RFC asks for and it is also
 * the only honest answer -- the alternative is to throw away bytes the sender meant,
 * or to show a mangled guess with nothing to say it is one.
 */
final readonly class EncodedWordDecoder
{
    public function __construct(private CharsetConverter $charsets = new CharsetConverter())
    {
    }

    public function decode(string $value): string
    {
        $out = '';
        $length = strlen($value);
        $i = 0;
        // Whitespace held back because it MIGHT sit between two encoded-words, in
        // which case it is not part of anybody's text.
        $pendingSpace = '';
        $previousWasEncodedWord = false;

        while ($i < $length) {
            if ('=' === $value[$i] && $i + 1 < $length && '?' === $value[$i + 1]) {
                $word = $this->readEncodedWord($value, $i);
                if (null !== $word) {
                    // Drop the held whitespace only when it separates two words.
                    if (!$previousWasEncodedWord) {
                        $out .= $pendingSpace;
                    }
                    $pendingSpace = '';
                    $out .= $word['text'];
                    $i = $word['end'];
                    $previousWasEncodedWord = true;

                    continue;
                }
            }

            $char = $value[$i];
            if (ctype_space($char)) {
                $pendingSpace .= $char;
                ++$i;

                continue;
            }

            // Ordinary text: whatever whitespace preceded it belongs to it.
            $out .= $pendingSpace . $char;
            $pendingSpace = '';
            $previousWasEncodedWord = false;
            ++$i;
        }

        // Trailing whitespace is part of the value unless it followed an
        // encoded-word with nothing after it, in which case it was never text.
        return $previousWasEncodedWord ? $out : $out . $pendingSpace;
    }

    /**
     * Read one encoded-word starting at `$start`, or null when what is there is not
     * a usable one.
     *
     * @return array{text: string, end: int}|null
     */
    private function readEncodedWord(string $value, int $start): ?array
    {
        $charsetEnd = strpos($value, '?', $start + 2);
        if (false === $charsetEnd) {
            return null;
        }
        $charset = substr($value, $start + 2, $charsetEnd - $start - 2);

        // The encoding is exactly one letter, followed by '?'.
        if ($charsetEnd + 2 >= strlen($value) || '?' !== $value[$charsetEnd + 2]) {
            return null;
        }
        $encoding = strtoupper($value[$charsetEnd + 1]);

        $textStart = $charsetEnd + 3;
        $terminator = strpos($value, '?=', $textStart);
        if (false === $terminator) {
            return null;
        }
        $encodedText = substr($value, $textStart, $terminator - $textStart);

        $decoded = match ($encoding) {
            'B' => base64_decode($encodedText, true),
            // RFC 2047 section 4.2: '_' is a space, and it is NOT the same rule as
            // quoted-printable, which leaves underscores alone.
            'Q' => quoted_printable_decode(str_replace('_', '=20', $encodedText)),
            default => false,
        };

        if (false === $decoded) {
            return null;
        }

        return [
            'text' => $this->charsets->toUtf8($decoded, $charset)->text,
            'end' => $terminator + 2,
        ];
    }
}
