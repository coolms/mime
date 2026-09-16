<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Conformance;

use Attribute;

/**
 * Marks a test as the evidence for one conformance row.
 *
 * The matrix is built by joining these attributes with the RESULT of the run, so a
 * row's status is a measurement rather than a claim: a test that exists but fails
 * leaves its row unverified, and the build refuses. Writing the attribute is
 * therefore not a way to fill a cell -- only a passing test fills a cell.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Conformance
{
    public function __construct(
        /** A row slug from {@see ConformanceMatrix}. */
        public string $row,
        /**
         * What the test ran against: `unit` for a hand-written case, `corpus` for the
         * shared corpus, `oracle` for a differential comparison with another parser.
         *
         * Stronger evidence does not replace weaker -- a row verified against the
         * oracle AND by unit cases says more than either alone, and the matrix shows
         * all of them.
         */
        public string $against = 'unit',
    ) {
    }
}
