<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Oracle;

use function hash;
use function strlen;

/**
 * A message's STRUCTURE, reduced to things two different parsers can be compared on
 * without either one's content leaving the process.
 *
 * Content is compared as a digest and a length, never as bytes. That is not
 * squeamishness: a divergence report is written to a file, pasted into a ticket and
 * read by strangers, and a report that quoted the bodies it disagreed about would be
 * a corpus leak with extra steps. A digest answers "are these the same bytes",
 * which is the only question worth asking, and a length turns "different" into
 * "different by how much" without saying how.
 */
final readonly class Shape
{
    /**
     * @param list<string> $leafTypes      content type of every part that carries bytes, in tree order
     * @param list<string> $filenames      every non-empty filename, in tree order
     * @param list<string> $contentDigests one digest per leaf, in the same order as $leafTypes
     * @param list<int>    $contentLengths one decoded length per leaf
     */
    public function __construct(
        public string $subject,
        public array $leafTypes,
        public array $filenames,
        public array $contentDigests,
        public array $contentLengths,
        public int $attachmentCount,
    ) {
    }

    public static function digest(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public static function lengthOf(string $bytes): int
    {
        return strlen($bytes);
    }

    /**
     * Which FIELDS differ between two shapes -- as slugs, never as values.
     *
     * @return list<string>
     */
    public function divergencesFrom(self $other): array
    {
        $found = [];

        if ($this->subject !== $other->subject) {
            $found[] = 'subject';
        }
        if ($this->leafTypes !== $other->leafTypes) {
            $found[] = 'leaf-types';
        }
        if ($this->filenames !== $other->filenames) {
            $found[] = 'filenames';
        }
        if ($this->contentDigests !== $other->contentDigests) {
            $found[] = 'content-bytes';
        }
        if ($this->contentLengths !== $other->contentLengths) {
            $found[] = 'content-lengths';
        }
        if ($this->attachmentCount !== $other->attachmentCount) {
            $found[] = 'attachment-count';
        }

        return $found;
    }
}
