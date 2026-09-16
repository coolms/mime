<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Conformance;

/**
 * What this parser CLAIMS to do, as a list.
 *
 * "Complete" is not a promise that can be checked; a list is. Every row here is
 * either implemented -- in which case the build refuses unless a passing test has
 * claimed it -- or explicitly NOT implemented, with a reason. A capability that is
 * simply absent from this file is the one thing the arrangement forbids, because
 * completeness by omission is how a library ends up claiming everything and
 * verifying nothing.
 *
 * The rows are the declaration. The STATUS comes from the test run
 * ({@see Conformance}), which is why this file cannot make a row green by itself.
 */
final readonly class ConformanceMatrix
{
    public const string IMPLEMENTED = 'implemented';

    public const string NOT_IMPLEMENTED = 'not implemented';

    /** @var array<string, array{title: string, rfc: string, status: string, note: string}> */
    private const array ROWS = [
        // -- RFC 5322: message and header syntax ---------------------------------
        'rfc5322-header-fields' => [
            'title' => 'Header fields, names and values',
            'rfc' => 'RFC 5322 s2.2',
            'status' => self::IMPLEMENTED,
            'note' => 'Case-insensitive lookup; the name is reported as written.',
        ],
        'rfc5322-folding' => [
            'title' => 'Folding and unfolding',
            'rfc' => 'RFC 5322 s2.2.3',
            'status' => self::IMPLEMENTED,
            'note' => 'The line break is removed and the whitespace kept.',
        ],
        'rfc5322-duplicate-fields' => [
            'title' => 'Repeated header fields',
            'rfc' => 'RFC 5322 s3.6',
            'status' => self::IMPLEMENTED,
            'note' => 'All kept in order; a singular read answers with the first.',
        ],
        'rfc5322-malformed-lines' => [
            'title' => 'Header lines that are neither a field nor a continuation',
            'rfc' => 'RFC 5322 s2.2',
            'status' => self::IMPLEMENTED,
            'note' => 'Counted and reported as a defect rather than guessed at.',
        ],
        'line-endings' => [
            'title' => 'CRLF, bare LF and bare CR line endings',
            'rfc' => 'RFC 5322 s2.1',
            'status' => self::IMPLEMENTED,
            'note' => 'Observed per line, not assumed; the terminator length feeds the body arithmetic.',
        ],

        // -- RFC 2045: content headers and transfer encodings --------------------
        'rfc2045-content-type' => [
            'title' => 'Content-Type with parameters',
            'rfc' => 'RFC 2045 s5',
            'status' => self::IMPLEMENTED,
            'note' => 'Quote-aware parameter split; a value may contain the separator.',
        ],
        'rfc2045-default-type' => [
            'title' => 'Default content type when none is declared',
            'rfc' => 'RFC 2045 s5.2',
            'status' => self::IMPLEMENTED,
            'note' => 'text/plain; charset=us-ascii, and message/rfc822 inside a digest.',
        ],
        'rfc2045-base64' => [
            'title' => 'base64 transfer decoding',
            'rfc' => 'RFC 2045 s6.8',
            'status' => self::IMPLEMENTED,
            'note' => 'Applied as a stream filter, so a large part is never held whole.',
        ],
        'rfc2045-quoted-printable' => [
            'title' => 'quoted-printable transfer decoding',
            'rfc' => 'RFC 2045 s6.7',
            'status' => self::IMPLEMENTED,
            'note' => 'Stream filter; soft line breaks included.',
        ],
        'rfc2045-identity-encodings' => [
            'title' => '7bit, 8bit and binary',
            'rfc' => 'RFC 2045 s6.2',
            'status' => self::IMPLEMENTED,
            'note' => 'Passed through; their decoded size is their encoded size.',
        ],
        'rfc2045-unknown-encoding' => [
            'title' => 'An unrecognised Content-Transfer-Encoding',
            'rfc' => 'RFC 2045 s6.4',
            'status' => self::IMPLEMENTED,
            'note' => 'Treated as opaque and reported as a defect, never discarded.',
        ],

        // -- RFC 2046: multipart structure ---------------------------------------
        'rfc2046-boundaries' => [
            'title' => 'Multipart boundaries and delimiters',
            'rfc' => 'RFC 2046 s5.1.1',
            'status' => self::IMPLEMENTED,
            'note' => 'A line that merely begins with the boundary is not a delimiter.',
        ],
        'rfc2046-delimiter-owns-the-break' => [
            'title' => 'The line break before a delimiter belongs to the delimiter',
            'rfc' => 'RFC 2046 s5.1.1',
            'status' => self::IMPLEMENTED,
            'note' => 'One or two bytes; getting it wrong corrupts every base64 part.',
        ],
        'rfc2046-nested-multipart' => [
            'title' => 'Nested multiparts',
            'rfc' => 'RFC 2046 s5.1.1',
            'status' => self::IMPLEMENTED,
            'note' => 'Recursive descent over ranges; depth is not bounded by the parser.',
        ],
        'rfc2046-preamble-epilogue' => [
            'title' => 'Preamble and epilogue are ignored',
            'rfc' => 'RFC 2046 s5.1.1',
            'status' => self::IMPLEMENTED,
            'note' => 'Including an epilogue that mentions the boundary again.',
        ],
        'rfc2046-unterminated' => [
            'title' => 'A multipart with no closing delimiter',
            'rfc' => 'RFC 2046 s5.1.1',
            'status' => self::IMPLEMENTED,
            'note' => 'The last part runs to the end of the multipart and the defect is recorded.',
        ],
        'rfc2046-digest' => [
            'title' => 'multipart/digest',
            'rfc' => 'RFC 2046 s5.1.5',
            'status' => self::IMPLEMENTED,
            'note' => 'Changes the default content type of its parts.',
        ],
        'rfc2046-message-rfc822' => [
            'title' => 'message/rfc822 as an enclosed message',
            'rfc' => 'RFC 2046 s5.2.1',
            'status' => self::IMPLEMENTED,
            'note' => 'Parsed as a whole message; its own attachments are its own.',
        ],
        'rfc2046-message-partial' => [
            'title' => 'message/partial reassembly',
            'rfc' => 'RFC 2046 s5.2.2',
            'status' => self::NOT_IMPLEMENTED,
            'note' => 'Reassembly needs several messages and a store to hold them, which is an application concern rather than a parser one. The part is surfaced with its type and parameters so a caller can do it.',
        ],
        'rfc2046-message-external-body' => [
            'title' => 'message/external-body retrieval',
            'rfc' => 'RFC 2046 s5.2.3',
            'status' => self::NOT_IMPLEMENTED,
            'note' => 'Deliberate: fetching content named by a message is a network act, and a parser that made one would be a request forger with a MIME hobby.',
        ],

        // -- RFC 2047: encoded words ---------------------------------------------
        'rfc2047-base64-word' => [
            'title' => 'B-encoded words',
            'rfc' => 'RFC 2047 s4.1',
            'status' => self::IMPLEMENTED,
            'note' => '',
        ],
        'rfc2047-quoted-word' => [
            'title' => 'Q-encoded words, including underscore as space',
            'rfc' => 'RFC 2047 s4.2',
            'status' => self::IMPLEMENTED,
            'note' => 'Not the quoted-printable rule, which leaves underscores alone.',
        ],
        'rfc2047-adjacency' => [
            'title' => 'Whitespace between adjacent encoded words is dropped',
            'rfc' => 'RFC 2047 s6.2',
            'status' => self::IMPLEMENTED,
            'note' => 'And whitespace beside ordinary text is kept. The reason the decoder is a scanner.',
        ],
        'rfc2047-malformed-word' => [
            'title' => 'A malformed encoded word is left literal',
            'rfc' => 'RFC 2047 s6.3',
            'status' => self::IMPLEMENTED,
            'note' => '',
        ],
        'rfc2047-in-parameters' => [
            'title' => 'Encoded words in a filename parameter',
            'rfc' => 'RFC 2047 s5 (violation, tolerated)',
            'status' => self::IMPLEMENTED,
            'note' => 'Forbidden there and ubiquitous; refusing shows a person the raw encoded word as their attachment name.',
        ],

        // -- RFC 2048 / 2049 ------------------------------------------------------
        'rfc2048-registration' => [
            'title' => 'Media type registration procedures',
            'rfc' => 'RFC 2048',
            'status' => self::NOT_IMPLEMENTED,
            'note' => 'An IANA process document with no parser surface. Listed so its absence is a statement rather than a gap.',
        ],
        'rfc2049-conformance' => [
            'title' => 'Conformance criteria for a MIME reader',
            'rfc' => 'RFC 2049 s2',
            'status' => self::IMPLEMENTED,
            'note' => 'The criteria are the rows above; this row records that they were read as a checklist rather than assumed.',
        ],

        // -- RFC 2183: disposition ------------------------------------------------
        'rfc2183-disposition' => [
            'title' => 'Content-Disposition: inline and attachment',
            'rfc' => 'RFC 2183 s2',
            'status' => self::IMPLEMENTED,
            'note' => '',
        ],
        'rfc2183-filename' => [
            'title' => 'The filename parameter',
            'rfc' => 'RFC 2183 s2.3',
            'status' => self::IMPLEMENTED,
            'note' => 'Falling back to the Content-Type name parameter, which predates it.',
        ],

        // -- RFC 2231: parameter continuations and charsets -----------------------
        'rfc2231-continuation' => [
            'title' => 'Parameter value continuations',
            'rfc' => 'RFC 2231 s3',
            'status' => self::IMPLEMENTED,
            'note' => '',
        ],
        'rfc2231-out-of-order' => [
            'title' => 'Continuation segments arriving out of order',
            'rfc' => 'RFC 2231 s3',
            'status' => self::IMPLEMENTED,
            'note' => 'Sorted by index; arrival order produces a value with its halves swapped.',
        ],
        'rfc2231-extended-value' => [
            'title' => 'Extended parameter values with charset and language',
            'rfc' => 'RFC 2231 s4',
            'status' => self::IMPLEMENTED,
            'note' => 'The charset comes from segment 0 and nowhere else.',
        ],
        'rfc2231-charset-language-in-word' => [
            'title' => 'A language tag on an encoded-word charset',
            'rfc' => 'RFC 2231 s5',
            'status' => self::IMPLEMENTED,
            'note' => '',
        ],

        // -- RFC 6532: internationalised headers ----------------------------------
        'rfc6532-utf8-headers' => [
            'title' => 'UTF-8 header values',
            'rfc' => 'RFC 6532 s3.2',
            'status' => self::IMPLEMENTED,
            'note' => 'Passed through intact rather than mangled into an encoded-word.',
        ],
        'rfc6532-message-global' => [
            'title' => 'message/global as an enclosed message',
            'rfc' => 'RFC 6532 s3.5',
            'status' => self::IMPLEMENTED,
            'note' => 'Parses identically to message/rfc822.',
        ],

        // -- charsets and streaming, which no single RFC owns ---------------------
        'charset-conversion' => [
            'title' => 'Conversion of a declared charset to UTF-8',
            'rfc' => 'RFC 2045 s5.2',
            'status' => self::IMPLEMENTED,
            'note' => 'Neither ext-mbstring nor ext-iconv is required.',
        ],
        'charset-fallback-reported' => [
            'title' => 'The charset actually used is reported',
            'rfc' => '-',
            'status' => self::IMPLEMENTED,
            'note' => 'A silent fallback turns a guess into an assertion nobody can audit.',
        ],
        'streaming-part-body' => [
            'title' => 'A part body is a stream over a range, not a copy',
            'rfc' => '-',
            'status' => self::IMPLEMENTED,
            'note' => 'Several part streams may be open over one message at once.',
        ],
        'size-without-decoding' => [
            'title' => 'Decoded size known without decoding, or null',
            'rfc' => '-',
            'status' => self::IMPLEMENTED,
            'note' => 'base64 exactly; quoted-printable answers null rather than guessing.',
        ],
        'non-seekable-source' => [
            'title' => 'A non-seekable source is buffered once',
            'rfc' => '-',
            'status' => self::IMPLEMENTED,
            'note' => 'A range means nothing on a socket, so the copy happens up front rather than per part.',
        ],
    ];

    /**
     * @return array<string, array{title: string, rfc: string, status: string, note: string}>
     */
    public static function rows(): array
    {
        return self::ROWS;
    }

    /**
     * Rows that must be backed by a passing test.
     *
     * @return list<string>
     */
    public static function implementedRows(): array
    {
        $rows = [];
        foreach (self::ROWS as $slug => $row) {
            if (self::IMPLEMENTED === $row['status']) {
                $rows[] = $slug;
            }
        }

        return $rows;
    }

    public static function has(string $slug): bool
    {
        return isset(self::ROWS[$slug]);
    }
}
