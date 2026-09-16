<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Unit;

use CoolMS\Mime\MessageParser;
use CoolMS\Mime\Tests\Corpus\Corpus;
use CoolMS\Mime\Tests\Corpus\CorpusMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function implode;
use function sort;

/**
 * Every corpus message through THIS parser, with no oracle involved.
 *
 * The oracle suite needs a dev dependency and can be absent; this one cannot. It is
 * what keeps the corpus honest on its own terms: every message parses, every message
 * that claims a defect produces exactly that defect, and -- the assertion that earns
 * the rest -- every message that claims NO defect produces none, so a parser quietly
 * reporting a defect everywhere would fail here rather than look thorough.
 */
final class CorpusTest extends TestCase
{
    private MessageParser $parser;

    /**
     * @return iterable<string, array{0: CorpusMessage}>
     */
    public static function corpus(): iterable
    {
        foreach (Corpus::all() as $message) {
            yield $message->name => [$message];
        }
    }

    #[Test]
    #[DataProvider('corpus')]
    public function everyCorpusMessageParsesIntoAtLeastOnePart(CorpusMessage $case): void
    {
        $message = $this->parser->parse($case->raw);

        self::assertNotSame([], $message->allParts(), $case->name);
        self::assertNotSame('', $message->root->contentType->full(), $case->name);
    }

    /**
     * The defects a message claims are exactly the defects it produces -- in both
     * directions. A parser that reported `unterminated-multipart` for everything
     * would satisfy the first half and fail the second.
     */
    #[Test]
    #[DataProvider('corpus')]
    public function aCorpusMessageProducesExactlyTheDefectsItClaims(CorpusMessage $case): void
    {
        $found = $this->parser->parse($case->raw)->defects();
        sort($found);
        $expected = $case->expectDefects;
        sort($expected);

        self::assertSame(
            $expected,
            $found,
            $case->name . ' -- expected [' . implode(', ', $expected) . '] and found [' . implode(', ', $found) . ']',
        );
    }

    /** A corpus whose entries share a name would silently test one of them twice. */
    #[Test]
    public function everyCorpusMessageHasADistinctNameAndNamesItsConstructs(): void
    {
        $names = [];
        foreach (Corpus::all() as $message) {
            self::assertArrayNotHasKey($message->name, $names, 'duplicate corpus name: ' . $message->name);
            $names[$message->name] = true;

            self::assertNotSame([], $message->constructs, $message->name . ' names no construct, so a divergence in it could not be reported by construct');
        }

        self::assertGreaterThanOrEqual(25, count($names), 'the corpus should not shrink by accident');
    }

    protected function setUp(): void
    {
        $this->parser = new MessageParser();
    }
}
