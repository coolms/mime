<?php

declare(strict_types=1);

namespace CoolMS\Mime\Header;

use function array_values;
use function count;
use function strtolower;

/**
 * The header fields of one part, in the order they appeared, with case-insensitive
 * lookup.
 *
 * **Duplicates are kept, and the FIRST wins on a singular read.** Real mail carries
 * repeated fields legitimately (`Received`, `Received-SPF`) and illegitimately (two
 * `Content-Type` lines from a broken generator). Discarding the extras would lose a
 * trace; averaging or concatenating them would invent a value nobody sent. So they
 * are all here, {@see all()} returns them in order, and {@see get()} answers with the
 * first -- which is what a reader means by "the Subject" and what every other parser
 * does with a message that has two.
 */
final readonly class Headers
{
    /** @var list<HeaderField> */
    private array $fields;

    /**
     * @param list<HeaderField> $fields in the order they appeared
     */
    public function __construct(array $fields = [])
    {
        $this->fields = array_values($fields);
    }

    /** The first field with this name, or null. Name comparison is case-insensitive. */
    public function get(string $name): ?HeaderField
    {
        $key = strtolower($name);
        foreach ($this->fields as $field) {
            if ($field->key() === $key) {
                return $field;
            }
        }

        return null;
    }

    /** The first field's raw value, or `$default` when there is no such field. */
    public function value(string $name, string $default = ''): string
    {
        return $this->get($name)?->value ?? $default;
    }

    /**
     * Every field with this name, in order.
     *
     * @return list<HeaderField>
     */
    public function all(string $name): array
    {
        $key = strtolower($name);
        $found = [];
        foreach ($this->fields as $field) {
            if ($field->key() === $key) {
                $found[] = $field;
            }
        }

        return $found;
    }

    public function has(string $name): bool
    {
        return null !== $this->get($name);
    }

    /**
     * Every field, in order, duplicates included.
     *
     * @return list<HeaderField>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function count(): int
    {
        return count($this->fields);
    }
}
