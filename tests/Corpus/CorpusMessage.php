<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Corpus;

/**
 * One corpus message: the bytes, and what it is FOR.
 *
 * The constructs are the point. A divergence report that said "message 7 differs" is
 * useless and, worse, tempts somebody to paste the message into a ticket -- so a
 * finding is reported as "this parser disagrees on RFC 2231 continuations", which is
 * actionable and carries no content at all.
 */
final readonly class CorpusMessage
{
    /**
     * @param list<string> $constructs stable slugs -- what this message exercises
     * @param list<string> $expectDefects defect slugs our parser must report for it
     */
    public function __construct(
        public string $name,
        public array $constructs,
        public string $raw,
        public array $expectDefects = [],
    ) {
    }
}
