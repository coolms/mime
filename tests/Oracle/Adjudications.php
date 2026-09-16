<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Oracle;

/**
 * Known divergences between this parser and the oracle, each with a verdict and the
 * clause it rests on.
 *
 * **This table is what makes the oracle a gate rather than a log.** A divergence not
 * listed here FAILS the comparison. Without that, a report of forty differences is
 * something a person skims once and never reads again, and the fortieth -- the one
 * that was a real defect -- is indistinguishable from the thirty-nine that were
 * modelling choices.
 *
 * **And an entry that explains nothing fails too.** A permission nobody uses reads as
 * "we thought about this" while recording only that somebody guessed; the sweep in
 * {@see OracleComparisonTest::noAdjudicationSitsInTheTableWithoutADivergenceToExplain()}
 * refuses one. Thirteen speculative entries were removed the first time it ran, and
 * what they revealed is worth more than they were: nested multiparts three deep,
 * multipart/alternative, embedded messages, a body that lies about its
 * Content-Transfer-Encoding and a body that lies about its charset all agree with the
 * oracle exactly.
 *
 * A verdict is one of:
 *
 *  - `ours`   -- we are right and the oracle is wrong. Rare, and it needs the clause.
 *  - `theirs` -- the oracle is right and this is a defect to fix. Listing it is a
 *                promise, not an excuse; it should not survive long.
 *  - `both`   -- the RFC does not determine an answer, or the two libraries model the
 *                same bytes differently. The clause says why nobody is wrong.
 *
 * Keyed by `<construct>:<divergence>` so one construct can diverge in several ways
 * and each gets its own argument.
 */
final readonly class Adjudications
{
    /** @var array<string, array{verdict: string, clause: string, why: string}> */
    private const array TABLE = [
        // -- an empty part: present in the bytes, filtered by the oracle ---------
        //
        // The immediate cause is the oracle's `hasContent()` returning false for a
        // part with a zero-length body, so it never reaches its part list while it
        // does reach ours. Recorded as a modelling difference rather than papered
        // over by filtering our side to match: an empty part IS in the message, and
        // aligning the two extractors would hide a real structural disagreement
        // behind a tidier report.
        'part-without-body:leaf-types' => [
            'verdict' => 'both',
            'clause' => 'RFC 2046 s5.1.1',
            'why' => 'A part whose header block is closed by the next delimiter has an empty body. This parser surfaces it; the oracle filters parts with no content out of its list.',
        ],
        'part-without-body:content-bytes' => [
            'verdict' => 'both',
            'clause' => 'RFC 2046 s5.1.1',
            'why' => 'Follows from the empty part being in one list and not the other.',
        ],
        'part-without-body:content-lengths' => [
            'verdict' => 'both',
            'clause' => 'RFC 2046 s5.1.1',
            'why' => 'Follows from the empty part being in one list and not the other.',
        ],
        'headers-only:leaf-types' => [
            'verdict' => 'both',
            'clause' => 'RFC 5322 s2.1',
            'why' => 'A message that is all headers has a body of zero length rather than no body. This parser reports the part; the oracle filters it as having no content.',
        ],
        'headers-only:content-bytes' => [
            'verdict' => 'both',
            'clause' => 'RFC 5322 s2.1',
            'why' => 'Follows from the same filtering.',
        ],
        'headers-only:content-lengths' => [
            'verdict' => 'both',
            'clause' => 'RFC 5322 s2.1',
            'why' => 'Follows from the same filtering.',
        ],

        // -- a digest's enclosed messages ---------------------------------------
        'multipart-digest:leaf-types' => [
            'verdict' => 'both',
            'clause' => 'RFC 2046 s5.1.5',
            'why' => 'Inside a digest a part with no Content-Type is message/rfc822. Whether the enclosed message is then a leaf or a container to descend into is a modelling choice; this parser stops at it.',
        ],
        'multipart-digest:attachment-count' => [
            'verdict' => 'both',
            'clause' => 'RFC 2046 s5.1.5 / RFC 2183 s2',
            'why' => 'A digest is a list of enclosed messages. This parser counts each as an attachment, since that is how a reader would save one; the oracle counts none, since none carries a Content-Disposition. Nothing in either RFC makes one of those wrong.',
        ],

        // -- where the bytes determine nothing -----------------------------------
        'nested-reused-boundary:leaf-types' => [
            'verdict' => 'both',
            'clause' => 'RFC 2046 s5.1.1',
            'why' => 'A boundary is required to be unique. When it is not, nothing in the bytes says which multipart a delimiter belongs to, and any tree is a guess. Survival is the only requirement.',
        ],
        'nested-reused-boundary:content-bytes' => [
            'verdict' => 'both',
            'clause' => 'RFC 2046 s5.1.1',
            'why' => 'Same cause.',
        ],
        'nested-reused-boundary:content-lengths' => [
            'verdict' => 'both',
            'clause' => 'RFC 2046 s5.1.1',
            'why' => 'Same cause.',
        ],

        // -- header bytes nobody can be right about ------------------------------
        'eight-bit-headers:subject' => [
            'verdict' => 'both',
            'clause' => 'RFC 5322 s2.2 / RFC 6532 s3.2',
            'why' => 'Raw 8-bit bytes in a header are forbidden by 5322 and permitted as UTF-8 by 6532. A byte sequence valid in neither leaves each parser to choose, and both choices lose information the sender never encoded.',
        ],
        'encoded-word-broken:subject' => [
            'verdict' => 'both',
            'clause' => 'RFC 2047 s6.3',
            'why' => 'A malformed encoded-word "should be treated as ordinary text". Whether the whitespace around it is then ordinary text too is not stated, and the two differ only there.',
        ],
    ];

    /**
     * @return array{verdict: string, clause: string, why: string}|null
     */
    public static function find(string $construct, string $divergence): ?array
    {
        return self::TABLE[$construct . ':' . $divergence] ?? null;
    }

    /**
     * Entries that claim the oracle is right -- a list of promises, which a reader
     * should expect to shrink.
     *
     * @return list<string>
     */
    public static function owed(): array
    {
        $owed = [];
        foreach (self::TABLE as $key => $entry) {
            if ('theirs' === $entry['verdict']) {
                $owed[] = $key;
            }
        }

        return $owed;
    }

    /**
     * @return array<string, array{verdict: string, clause: string, why: string}>
     */
    public static function all(): array
    {
        return self::TABLE;
    }
}
