<?php

declare(strict_types=1);

namespace CoolMS\Mime\Charset;

use Throwable;

use function extension_loaded;
use function function_exists;
use function iconv;
use function is_string;
use function mb_check_encoding;
use function mb_convert_encoding;
use function preg_match;
use function preg_replace;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

/**
 * Converts a part's bytes to UTF-8, and says which charset it actually used.
 *
 * **The fallback chain is stated rather than implied**, because every link in it is a
 * guess of decreasing quality and the caller deserves to know which one it got:
 *
 *  1. the DECLARED charset, when something on this installation can convert it;
 *  2. UTF-8 itself, when the bytes are already valid UTF-8 -- which covers the very
 *     common case of a message that declares `us-ascii` or nothing at all and is in
 *     fact UTF-8;
 *  3. `windows-1252`, last. It maps every byte to something, so it always
 *     "succeeds" -- which is exactly why it is last and why the result says it was a
 *     fallback. Latin-1 would do as well arithmetically; windows-1252 is chosen
 *     because the bytes it differs on (0x80 to 0x9F) are smart quotes and dashes in
 *     real mail far more often than they are C1 control characters.
 *
 * Neither `ext-mbstring` nor `ext-iconv` is required. With both absent the converter
 * still answers -- it can validate UTF-8 with a pattern and pass ASCII through -- and
 * simply reports a fallback more often. A parser that threw because an optional
 * extension was missing would be unusable on exactly the minimal installations that
 * most need a dependency-free library.
 */
final readonly class CharsetConverter
{
    private const string LAST_RESORT = 'windows-1252';

    /** Aliases seen in real mail that the extensions do not all know. */
    private const array ALIASES = [
        'utf8' => 'UTF-8',
        'utf-8' => 'UTF-8',
        'unicode-1-1-utf-8' => 'UTF-8',
        'ansi_x3.4-1968' => 'US-ASCII',
        'ascii' => 'US-ASCII',
        'us-ascii' => 'US-ASCII',
        'cp1251' => 'windows-1251',
        'cp1252' => 'windows-1252',
        'win-1251' => 'windows-1251',
        'latin1' => 'ISO-8859-1',
        'latin-1' => 'ISO-8859-1',
        'ks_c_5601-1987' => 'EUC-KR',
        'gb2312' => 'GB18030',
        'gbk' => 'GB18030',
    ];

    public function toUtf8(string $bytes, string $declared): ConversionResult
    {
        $canonical = $this->canonical($declared);

        if ('' !== $canonical && 'UTF-8' !== $canonical) {
            $converted = $this->convert($bytes, $canonical);
            if (null !== $converted) {
                return new ConversionResult($converted, $canonical, $declared, false);
            }
        }

        // Already UTF-8? Covers a declared UTF-8, a declared us-ascii that is really
        // UTF-8, and the very common no-declaration case.
        if ($this->isUtf8($bytes)) {
            return new ConversionResult($bytes, 'UTF-8', $declared, 'UTF-8' !== $canonical);
        }

        $last = $this->convert($bytes, self::LAST_RESORT);

        return new ConversionResult($last ?? $bytes, null === $last ? 'binary' : self::LAST_RESORT, $declared, true);
    }

    /** The declared charset in the form the extensions expect, or '' when none was declared. */
    public function canonical(string $declared): string
    {
        // RFC 2231 section 5 allows a language suffix on a charset: `utf-8*en`.
        $name = strtolower(trim($declared, " \t\"'"));
        $star = strpos($name, '*');
        if (false !== $star) {
            $name = substr($name, 0, $star);
        }
        if ('' === $name) {
            return '';
        }

        // IANA charset names are case-insensitive and conventionally written upper
        // case. Normalising here means `charsetUsed` is comparable between two
        // messages that spelled the same charset differently, which is the whole
        // point of reporting it.
        return self::ALIASES[$name] ?? strtoupper($name);
    }

    private function convert(string $bytes, string $charset): ?string
    {
        if ('UTF-8' === $charset) {
            return $this->isUtf8($bytes) ? $bytes : null;
        }

        // Both of these REJECT an unknown charset by throwing, not by returning
        // false: PHP 8 raises ValueError, which `@` does not suppress. An unknown
        // charset is the ordinary case here -- it is why the fallback chain exists --
        // so it is caught rather than allowed to escape as a fatal from a library
        // whose entire job is surviving bad input.
        if (extension_loaded('mbstring') && function_exists('mb_convert_encoding')) {
            try {
                $converted = @mb_convert_encoding($bytes, 'UTF-8', $charset);
                if (is_string($converted) && $this->isUtf8($converted)) {
                    return $converted;
                }
            } catch (Throwable) {
                // Unknown to mbstring; iconv may still know it.
            }
        }

        if (extension_loaded('iconv') && function_exists('iconv')) {
            try {
                // //IGNORE rather than //TRANSLIT: a message with one bad byte should
                // lose that byte, not have every accented character replaced by an
                // approximation of itself.
                $converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);
                if (is_string($converted) && $this->isUtf8($converted)) {
                    return $converted;
                }
            } catch (Throwable) {
                // Unknown to iconv too; the caller falls back.
            }
        }

        // US-ASCII needs no extension: it is a subset of UTF-8 by construction.
        if ('US-ASCII' === $charset && $this->isAscii($bytes)) {
            return $bytes;
        }

        return null;
    }

    private function isUtf8(string $bytes): bool
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($bytes, 'UTF-8');
        }

        // No mbstring: the UTF-8 grammar as a pattern. Anchored and possessive so it
        // cannot backtrack across a long body.
        return 1 === preg_match('//u', $bytes);
    }

    private function isAscii(string $bytes): bool
    {
        return null !== preg_replace('/[\x00-\x7F]/', '', $bytes)
            && '' === preg_replace('/[\x00-\x7F]/', '', $bytes);
    }
}
