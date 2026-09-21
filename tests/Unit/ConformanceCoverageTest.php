<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Unit;

use CoolMS\Mime\Tests\Conformance\ClaimedRows;
use CoolMS\Mime\Tests\Conformance\Conformance;
use CoolMS\Mime\Tests\Conformance\ConformanceMatrix;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function array_keys;
use function array_values;
use function implode;

/**
 * The static half of the conformance gate: every row this package claims to
 * implement is claimed by at least one test, and no test claims a row that does not
 * exist or that the matrix says is not implemented.
 *
 * The other half -- that those tests PASS -- cannot be answered from inside the run,
 * so it lives in `tools/build-matrix.php`, which joins these claims with the JUnit
 * results and refuses to write a matrix where an implemented row has no passing
 * evidence. Together the two mean a green cell is a measurement.
 */
final class ConformanceCoverageTest extends TestCase
{
    /** @var array<string, list<array{test: string, against: string}>> */
    private array $claims;

    /**
     * The assertion that stops completeness-by-omission: a row cannot be declared
     * implemented and then quietly never tested.
     */
    #[Conformance('rfc2049-conformance')]
    #[Test]
    public function everyImplementedRowIsClaimedByATest(): void
    {
        $unclaimed = array_values(array_diff(ConformanceMatrix::implementedRows(), array_keys($this->claims)));

        self::assertSame(
            [],
            $unclaimed,
            'These rows are declared implemented and no test claims them: ' . implode(', ', $unclaimed)
                . '. Either add #[Conformance(...)] to the test that proves it, or change the row to not implemented with a reason.',
        );
    }

    #[Test]
    public function noTestClaimsARowThatDoesNotExist(): void
    {
        $unknown = [];
        foreach (array_keys($this->claims) as $row) {
            if (!ConformanceMatrix::has($row)) {
                $unknown[] = $row;
            }
        }

        self::assertSame([], $unknown, 'Unknown conformance rows claimed: ' . implode(', ', $unknown));
    }

    /** Claiming a row the matrix says is not implemented is a contradiction, not a bonus. */
    #[Test]
    public function noTestClaimsARowDeclaredNotImplemented(): void
    {
        $implemented = ConformanceMatrix::implementedRows();
        $contradictions = [];

        foreach (array_keys($this->claims) as $row) {
            if (ConformanceMatrix::has($row) && !in_array($row, $implemented, true)) {
                $contradictions[] = $row;
            }
        }

        self::assertSame([], $contradictions, 'Claimed but declared not implemented: ' . implode(', ', $contradictions));
    }

    protected function setUp(): void
    {
        $this->claims = ClaimedRows::collect(__DIR__ . '/..');
    }
}
