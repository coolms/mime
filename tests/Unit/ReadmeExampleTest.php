<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function ob_get_clean;
use function ob_start;
use function preg_match;
use function preg_replace;

/**
 * The usage example in README.md is run, and what it prints is compared with the output block printed under it. A
 * change to the API that breaks the example, or to the example that is not carried into its output, fails here
 * rather than in a reader's first five minutes.
 */
final class ReadmeExampleTest extends TestCase
{
    #[Test]
    public function theUsageExamplePrintsWhatTheReadmeSaysItPrints(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertIsString($readme);
        $found = preg_match('/## Usage\n\n```php\n(.*?)\n```\n\nOutput:\n\n```text\n(.*?)\n```/s', $readme, $m);
        self::assertSame(1, $found, 'README.md has a Usage section: a php block, then "Output:" and a text block');

        // The block starts with its `use` lines, which eval() accepts at the top of the code it runs.
        $code = preg_replace('/^<\?php\s*/', '', $m[1]);
        ob_start();
        eval($code);
        $printed = ob_get_clean();

        self::assertSame($m[2] . "\n", $printed);
    }
}
