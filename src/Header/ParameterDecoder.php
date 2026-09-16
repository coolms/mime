<?php

declare(strict_types=1);

namespace CoolMS\Mime\Header;

use CoolMS\Mime\Charset\CharsetConverter;

use function array_pad;
use function ctype_digit;
use function explode;
use function ksort;
use function rawurldecode;
use function str_contains;
use function str_ends_with;
use function strrpos;
use function strtolower;
use function substr;

/**
 * Reassembles RFC 2231 parameters: continuations, extended values, and the two
 * together.
 *
 * Three shapes, and real mail uses all of them for one thing -- a filename too long
 * or too non-ASCII to sit in a quoted string:
 *
 *  - `name*=utf-8'en'%C3%BCber.txt` -- an extended value: charset, language,
 *    percent-encoded bytes;
 *  - `name*0="long "; name*1="filename.txt"` -- a continuation, concatenated by
 *    index;
 *  - `name*0*=utf-8''%C3%BC; name*1*=ber.txt` -- both, where the charset is declared
 *    once on segment 0 and each starred segment is percent-encoded.
 *
 * **Segments are sorted by index, not taken in arrival order.** A generator that
 * emits `name*1` before `name*0` is malformed and exists; concatenating as they
 * arrive produces a filename with its halves swapped, which is worse than either
 * failing or getting it right, because it looks plausible.
 *
 * **The charset comes from segment 0 and nowhere else.** A later segment carrying its
 * own charset is malformed per section 4, and honouring it would mean decoding one
 * filename as two encodings -- so it is ignored, and the whole reassembled value is
 * converted once.
 *
 * An unstarred `name` alongside a starred `name*` is resolved in favour of the
 * starred one: it carries strictly more information, and senders that emit both do
 * it precisely so an old reader takes the plain one.
 */
final readonly class ParameterDecoder
{
    public function __construct(
        private CharsetConverter $charsets = new CharsetConverter(),
        private EncodedWordDecoder $words = new EncodedWordDecoder(),
    ) {
    }

    /**
     * @param list<array{name: string, value: string, wasQuoted: bool}> $raw
     *
     * @return array<string, string> parameter name (lowercased) to decoded UTF-8 value
     */
    public function decode(array $raw): array
    {
        /** @var array<string, array<int, array{value: string, encoded: bool}>> $segments */
        $segments = [];
        /** @var array<string, string> $charsets */
        $charsets = [];
        /** @var array<string, string> $plain */
        $plain = [];

        foreach ($raw as $parameter) {
            $name = strtolower($parameter['name']);
            $value = $parameter['value'];

            if (!str_contains($name, '*')) {
                $plain[$name] = $value;

                continue;
            }

            [$base, $index, $isEncoded] = $this->dissect($name);

            if ($isEncoded && 0 === $index && str_contains($value, "'")) {
                // charset'language'bytes -- only the first segment carries it.
                [$charset, , $rest] = array_pad(explode("'", $value, 3), 3, '');
                $charsets[$base] = $charset;
                $value = $rest;
            }

            $segments[$base][$index] = ['value' => $value, 'encoded' => $isEncoded];
        }

        $decoded = [];

        foreach ($segments as $base => $parts) {
            // Index order, not arrival order.
            ksort($parts);
            $assembled = '';
            foreach ($parts as $part) {
                $assembled .= $part['encoded'] ? rawurldecode($part['value']) : $part['value'];
            }

            $charset = $charsets[$base] ?? '';
            $decoded[$base] = '' === $charset
                ? $assembled
                : $this->charsets->toUtf8($assembled, $charset)->text;
        }

        // A plain parameter fills in only where no starred one spoke for that name.
        foreach ($plain as $name => $value) {
            if (!isset($decoded[$name])) {
                // Not RFC 2231, but ubiquitous: senders put an RFC 2047 encoded-word
                // in a plain filename parameter. It is wrong and it is everywhere,
                // and refusing to decode it shows the user `=?utf-8?B?...?=` as the
                // name of their attachment.
                $decoded[$name] = $this->words->decode($value);
            }
        }

        return $decoded;
    }

    /**
     * Split a decorated name into base, segment index and whether that segment is
     * percent-encoded.
     *
     * @return array{0: string, 1: int, 2: bool}
     */
    private function dissect(string $name): array
    {
        $isEncoded = str_ends_with($name, '*');
        if ($isEncoded) {
            $name = substr($name, 0, -1);
        }

        $star = strrpos($name, '*');
        if (false === $star) {
            // `name*` with no index: an extended value in one piece.
            return [$name, 0, $isEncoded];
        }

        $index = substr($name, $star + 1);

        // `name*x` where x is not a number is not a continuation index; treat the
        // whole thing as the base name rather than inventing segment 0.
        if ('' === $index || !ctype_digit($index)) {
            return [$name, 0, $isEncoded];
        }

        return [substr($name, 0, $star), (int) $index, $isEncoded];
    }
}
