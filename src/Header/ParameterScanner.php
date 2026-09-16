<?php

declare(strict_types=1);

namespace CoolMS\Mime\Header;

use function array_shift;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * Splits a structured header value into its main value and its raw parameters.
 *
 * `multipart/mixed; boundary="a;b"; charset=utf-8` has THREE semicolons and TWO
 * parameters, and telling them apart means knowing which semicolon is inside a
 * quoted string. That is the second place in this library where an expression would
 * be wrong on real input: a boundary containing a semicolon is legal, generators do
 * emit one, and splitting on `;` turns one parameter into two and the boundary into
 * something that matches nothing.
 *
 * Escapes inside quotes are honoured too, so `filename="say \"hi\".txt"` is one
 * parameter whose value contains quotes.
 *
 * Nothing is decoded here. The names keep their RFC 2231 decoration (`name*0*`) and
 * the values keep their percent-encoding, because reassembling continuations needs
 * to see all the segments together -- which is {@see ParameterDecoder}'s job.
 */
final readonly class ParameterScanner
{
    /**
     * @return array{value: string, parameters: list<array{name: string, value: string, wasQuoted: bool}>}
     */
    public function scan(string $header): array
    {
        $segments = $this->split($header);
        $value = trim(array_shift($segments) ?? '');

        $parameters = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ('' === $segment) {
                continue;
            }

            $equals = strpos($segment, '=');
            if (false === $equals) {
                // A parameter with no value. Kept with an empty value rather than
                // dropped: `Content-Disposition: attachment; filename` says something
                // even though it says it badly.
                $parameters[] = ['name' => trim($segment), 'value' => '', 'wasQuoted' => false];

                continue;
            }

            $name = trim(substr($segment, 0, $equals));
            $raw = trim(substr($segment, $equals + 1));
            $quoted = '' !== $raw && '"' === $raw[0];

            $parameters[] = [
                'name' => $name,
                'value' => $quoted ? $this->unquote($raw) : $raw,
                'wasQuoted' => $quoted,
            ];
        }

        return ['value' => $value, 'parameters' => $parameters];
    }

    /**
     * Split on semicolons that are NOT inside a quoted string.
     *
     * @return list<string>
     */
    private function split(string $header): array
    {
        $segments = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($header);

        for ($i = 0; $i < $length; ++$i) {
            $char = $header[$i];

            if ($inQuotes && '\\' === $char && $i + 1 < $length) {
                // A backslash escapes the next character, including a quote, so the
                // quoted string does not end where it appears to.
                $current .= $char . $header[$i + 1];
                ++$i;

                continue;
            }

            if ('"' === $char) {
                $inQuotes = !$inQuotes;
                $current .= $char;

                continue;
            }

            if (';' === $char && !$inQuotes) {
                $segments[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $segments[] = $current;

        return $segments;
    }

    /** Strip the surrounding quotes and undo backslash escapes inside them. */
    private function unquote(string $raw): string
    {
        $length = strlen($raw);
        $end = $length;
        // An unterminated quoted string runs to the end rather than being discarded.
        if ($length > 1 && '"' === $raw[$length - 1]) {
            $end = $length - 1;
        }

        $out = '';
        for ($i = 1; $i < $end; ++$i) {
            if ('\\' === $raw[$i] && $i + 1 < $end) {
                $out .= $raw[$i + 1];
                ++$i;

                continue;
            }
            $out .= $raw[$i];
        }

        return $out;
    }
}
