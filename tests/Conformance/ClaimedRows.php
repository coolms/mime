<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Conformance;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

use function class_exists;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Finds every `#[Conformance]` claim in the test suite, by reflection.
 *
 * Reflection rather than a registry somebody maintains: a claim lives on the test
 * that proves it, so moving or deleting the test moves or deletes the claim, and the
 * two can never say different things.
 */
final readonly class ClaimedRows
{
    /**
     * Every claim in the suite: row slug to the tests claiming it, with what each ran
     * against.
     *
     * @return array<string, list<array{test: string, against: string}>>
     */
    public static function collect(string $testsRoot): array
    {
        $claims = [];

        foreach (self::testClasses($testsRoot) as $class) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Conformance::class) as $attribute) {
                    /** @var Conformance $conformance */
                    $conformance = $attribute->newInstance();
                    $claims[$conformance->row][] = [
                        'test' => $reflection->getShortName() . '::' . $method->getName(),
                        'against' => $conformance->against,
                    ];
                }
            }
        }

        return $claims;
    }

    /**
     * @return list<class-string>
     */
    private static function testClasses(string $testsRoot): array
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsRoot));

        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($testsRoot) + 1);
            $class = 'CoolMS\\Mime\\Tests\\' . str_replace(['/', '.php'], ['\\', ''], $relative);

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
