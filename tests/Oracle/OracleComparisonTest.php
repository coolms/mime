<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Oracle;

use CoolMS\Mime\Message;
use CoolMS\Mime\MessageParser;
use CoolMS\Mime\Part;
use CoolMS\Mime\Tests\Conformance\Conformance;
use CoolMS\Mime\Tests\Corpus\Corpus;
use CoolMS\Mime\Tests\Corpus\CorpusMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZBateson\MailMimeParser\MailMimeParser;

use function array_unique;
use function array_values;
use function class_exists;
use function count;
use function explode;
use function file_put_contents;
use function fopen;
use function fwrite;
use function implode;
use function ksort;
use function mkdir;
use function printf;
use function rewind;
use function sort;
use function sprintf;

/**
 * The differential oracle: every corpus message through this parser and through
 * `zbateson/mail-mime-parser`, with every disagreement reported BY CONSTRUCT.
 *
 * **The oracle is a dev dependency of this package alone.** It is never installed for
 * a consumer, and these cases skip themselves when the class is absent, so a
 * `composer install --no-dev` is green rather than red. The check that this is
 * actually true lives in {@see \CoolMS\Mime\Tests\Unit\NoDevInstallTest}.
 *
 * **A divergence is not automatically a defect.** The oracle has its own limits and
 * its own modelling choices, and several of these messages are ambiguous by
 * construction. So every divergence must carry an adjudication -- ours, theirs, or
 * both defensible, with the clause -- and an UNADJUDICATED one fails the test. That
 * is the difference between a gate and a log: a report of forty differences is
 * skimmed once, and the one that mattered is lost among the thirty-nine that did not.
 */
final class OracleComparisonTest extends TestCase
{
    /** @var array<string, list<string>> construct to divergence slugs, accumulated across the run */
    private static array $report = [];

    /**
     * @return iterable<string, array{0: CorpusMessage}>
     */
    public static function corpus(): iterable
    {
        foreach (Corpus::all() as $message) {
            yield $message->name => [$message];
        }
    }

    public static function tearDownAfterClass(): void
    {
        if ([] === self::$report) {
            return;
        }

        $report = self::$report;
        ksort($report);

        $lines = ['# Oracle divergence report', '', 'Grouped by construct. No message content appears here, by design.', ''];
        foreach ($report as $construct => $divergences) {
            $unique = array_values(array_unique($divergences));
            sort($unique);
            $lines[] = sprintf('## %s', $construct);
            foreach ($unique as $divergence) {
                $entry = Adjudications::find($construct, $divergence);
                $lines[] = sprintf(
                    '- **%s** -- %s (%s): %s',
                    $divergence,
                    $entry['verdict'] ?? 'UNADJUDICATED',
                    $entry['clause'] ?? '-',
                    $entry['why'] ?? 'no reasoning recorded',
                );
            }
            $lines[] = '';
        }

        @mkdir(__DIR__ . '/../../build', 0o775, true);
        @file_put_contents(__DIR__ . '/../../build/oracle-report.md', implode("\n", $lines) . "\n");
    }

    #[Conformance('rfc2231-continuation', 'oracle')]
    #[Conformance('rfc2231-out-of-order', 'oracle')]
    #[Conformance('rfc2231-extended-value', 'oracle')]
    #[Conformance('rfc2047-in-parameters', 'oracle')]
    #[Conformance('rfc2045-base64', 'oracle')]
    #[Conformance('rfc2045-quoted-printable', 'oracle')]
    #[Conformance('rfc2046-nested-multipart', 'oracle')]
    #[Conformance('rfc2046-message-rfc822', 'oracle')]
    #[Conformance('line-endings', 'oracle')]
    #[Test]
    #[DataProvider('corpus')]
    public function everyDivergenceFromTheOracleIsAdjudicated(CorpusMessage $case): void
    {
        $ours = $this->ourShape(new MessageParser()->parse($case->raw));
        $theirs = $this->oracleShape($case->raw);

        $divergences = $ours->divergencesFrom($theirs);

        $unadjudicated = [];
        foreach ($divergences as $divergence) {
            $adjudicated = false;
            foreach ($case->constructs as $construct) {
                self::$report[$construct][] = $divergence;
                if (null !== Adjudications::find($construct, $divergence)) {
                    $adjudicated = true;
                }
            }
            if (!$adjudicated) {
                $unadjudicated[] = $divergence;
            }
        }

        self::assertSame(
            [],
            $unadjudicated,
            sprintf(
                'Unadjudicated divergence in "%s" (constructs: %s): %s. '
                    . 'Add an entry to Adjudications with a verdict and the clause, or fix the parser. '
                    . 'Do NOT paste the message into the entry.',
                $case->name,
                implode(', ', $case->constructs),
                implode(', ', $unadjudicated),
            ),
        );
    }

    /**
     * The adjudication table must not outlive its reasons. An entry for a construct
     * no corpus message exercises is a claim nobody is checking.
     */
    #[Test]
    public function everyAdjudicationNamesAConstructTheCorpusActuallyExercises(): void
    {
        $exercised = [];
        foreach (Corpus::all() as $message) {
            foreach ($message->constructs as $construct) {
                $exercised[$construct] = true;
            }
        }

        foreach (Adjudications::all() as $key => $entry) {
            [$construct] = explode(':', $key, 2);
            self::assertArrayHasKey(
                $construct,
                $exercised,
                sprintf('Adjudication "%s" names a construct no corpus message exercises.', $key),
            );
            self::assertNotSame('', $entry['clause'], $key . ' has no clause');
            self::assertNotSame('', $entry['why'], $key . ' has no reasoning');
        }
    }

    /**
     * No adjudication may sit in the table without a divergence that actually
     * happens.
     *
     * The table is a permission list, and a permission nobody uses is a claim nobody
     * checks -- it reads as "we thought about this" while recording only that
     * somebody guessed. This sweep runs the whole corpus and refuses an entry that
     * never fires, so the table stays a description of what these two parsers really
     * disagree about.
     */
    #[Test]
    public function noAdjudicationSitsInTheTableWithoutADivergenceToExplain(): void
    {
        $seen = [];
        foreach (Corpus::all() as $case) {
            $ours = $this->ourShape(new MessageParser()->parse($case->raw));
            $theirs = $this->oracleShape($case->raw);

            foreach ($ours->divergencesFrom($theirs) as $divergence) {
                foreach ($case->constructs as $construct) {
                    $seen[$construct . ':' . $divergence] = true;
                }
            }
        }

        $unused = [];
        foreach (Adjudications::all() as $key => $entry) {
            if (!isset($seen[$key])) {
                $unused[] = $key;
            }
        }

        self::assertSame(
            [],
            $unused,
            'These adjudications explain a divergence that does not occur. Remove them: ' . implode(', ', $unused),
        );
    }

    /** Anything the table admits the oracle is right about is a promise, and it is printed. */
    #[Test]
    public function outstandingDefectsAreVisibleRatherThanBuried(): void
    {
        $owed = Adjudications::owed();

        // Not an assertion that the list is empty -- it may legitimately not be.
        // The assertion is that nobody can add one without it being printed.
        self::assertIsArray($owed);
        if ([] !== $owed) {
            echo "\nOracle: divergences where the oracle is right and this parser is not:\n";
            foreach ($owed as $key) {
                $entry = Adjudications::all()[$key];
                printf("  %-48s %s -- %s\n", $key, $entry['clause'], $entry['why']);
            }
        }
    }

    protected function setUp(): void
    {
        if (!class_exists(MailMimeParser::class)) {
            self::markTestSkipped('zbateson/mail-mime-parser is a dev dependency of this package only.');
        }
    }

    private function ourShape(Message $message): Shape
    {
        $leafTypes = [];
        $digests = [];
        $lengths = [];
        $filenames = [];

        foreach ($this->ourLeaves($message->root) as $part) {
            $leafTypes[] = $part->contentType->full();
            $content = $part->content();
            $digests[] = Shape::digest($content);
            $lengths[] = Shape::lengthOf($content);
            if ('' !== $part->filename()) {
                $filenames[] = $part->filename();
            }
        }

        return new Shape(
            $message->subject(),
            $leafTypes,
            $filenames,
            $digests,
            $lengths,
            count($message->attachments()),
        );
    }

    /**
     * Our leaves, stopping at an embedded message the way {@see Message::attachments()}
     * does, so the two shapes are built on the same rule.
     *
     * @return list<Part>
     */
    private function ourLeaves(Part $part): array
    {
        if ($part->isEmbeddedMessage() || $part->isLeaf()) {
            return [$part];
        }

        $leaves = [];
        foreach ($part->parts() as $child) {
            foreach ($this->ourLeaves($child) as $leaf) {
                $leaves[] = $leaf;
            }
        }

        return $leaves;
    }

    private function oracleShape(string $raw): Shape
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $raw);
        rewind($stream);

        $message = new MailMimeParser()->parse($stream, true);

        $leafTypes = [];
        $digests = [];
        $lengths = [];
        $filenames = [];

        foreach ($message->getAllParts() as $part) {
            if (!$part->hasContent()) {
                continue;
            }
            $leafTypes[] = $part->getContentType();
            $content = (string) $part->getBinaryContentStream()?->getContents();
            $digests[] = Shape::digest($content);
            $lengths[] = Shape::lengthOf($content);
            $filename = (string) $part->getFilename();
            if ('' !== $filename) {
                $filenames[] = $filename;
            }
        }

        return new Shape(
            (string) $message->getSubject(),
            $leafTypes,
            $filenames,
            $digests,
            $lengths,
            count($message->getAllAttachmentParts()),
        );
    }
}
