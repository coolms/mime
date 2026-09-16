<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function file_get_contents;
use function json_decode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * The oracle must never reach a consumer.
 *
 * `zbateson/mail-mime-parser` is a differential oracle for THIS package's tests, and
 * it drags in a second DI container (`php-di`) and a PSR-7 implementation. A consumer
 * installing this parser to AVOID dependencies would be poorly served by acquiring
 * three, so the arrangement is: dev requirement only, no reference from `src/`, and a
 * `composer install --no-dev` that leaves both absent from vendor.
 *
 * The last of those is checked by the CI workflow, because it needs a real install.
 * These two are checked here, because they are the ones a careless edit would break
 * and they can be checked with nothing installed at all.
 */
final class NoDevDependencyLeakTest extends TestCase
{
    /** The rule a consumer actually feels: nothing shipped may name the oracle. */
    #[Test]
    public function noSourceFileReferencesTheOracle(): void
    {
        $offenders = [];
        $root = __DIR__ . '/../../src';

        /** @var RecursiveDirectoryIterator $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'ZBateson') || str_contains($source, 'zbateson')) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders, 'the oracle is a test dependency and must not be named in src/');
    }

    #[Test]
    public function theRuntimeRequirementIsPhpAloneAndTheOracleIsADevRequirement(): void
    {
        /** @var array{require: array<string, string>, "require-dev": array<string, string>} $manifest */
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['php' => '^8.5'],
            $manifest['require'],
            'a dependency here is a dependency every consumer acquires, which is the thing this package exists to avoid',
        );
        self::assertArrayHasKey('zbateson/mail-mime-parser', $manifest['require-dev']);
        self::assertArrayNotHasKey('zbateson/mail-mime-parser', $manifest['require']);
        self::assertArrayNotHasKey('php-di/php-di', $manifest['require']);
    }
}
