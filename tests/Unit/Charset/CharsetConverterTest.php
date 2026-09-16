<?php

declare(strict_types=1);

namespace CoolMS\Mime\Tests\Unit\Charset;

use CoolMS\Mime\Charset\CharsetConverter;
use CoolMS\Mime\Tests\Conformance\Conformance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Conversion to UTF-8, and -- the part that matters as much as the text -- whether
 * the result admits to being a guess.
 */
final class CharsetConverterTest extends TestCase
{
    private CharsetConverter $converter;

    #[Conformance('charset-conversion')]
    #[Test]
    public function aDeclaredCharsetIsUsedAndNotReportedAsAFallback(): void
    {
        $result = $this->converter->toUtf8("Gr\xFC\xDFe", 'iso-8859-1');

        self::assertSame('Grüße', $result->text);
        self::assertSame('ISO-8859-1', $result->charsetUsed);
        self::assertFalse($result->wasFallback);
    }

    #[Test]
    public function utf8BytesPassThrough(): void
    {
        $result = $this->converter->toUtf8('Grüße', 'utf-8');

        self::assertSame('Grüße', $result->text);
        self::assertSame('UTF-8', $result->charsetUsed);
        self::assertFalse($result->wasFallback);
    }

    /**
     * The very common case: a message declares nothing, or declares us-ascii, and is
     * in fact UTF-8. Answering correctly is easy; the point of the assertion is that
     * the result SAYS it was a fallback, because the message never promised this.
     */
    #[Conformance('charset-fallback-reported')]
    #[Test]
    public function undeclaredUtf8IsDetectedAndTheGuessIsAdmitted(): void
    {
        $result = $this->converter->toUtf8('Grüße', '');

        self::assertSame('Grüße', $result->text);
        self::assertSame('UTF-8', $result->charsetUsed);
        self::assertTrue($result->wasFallback, 'nothing was declared, so this is inference');
        self::assertSame('', $result->declared);
    }

    /**
     * The last resort. windows-1252 maps every byte to something, so it always
     * "succeeds" -- which is exactly why the result must not look like a clean
     * conversion.
     */
    #[Conformance('charset-fallback-reported')]
    #[Test]
    public function bytesThatAreNeitherDeclaredNorValidUtf8FallBackAndSaySo(): void
    {
        $result = $this->converter->toUtf8("caf\xE9", 'x-nonexistent-charset-9000');

        self::assertSame('café', $result->text);
        self::assertSame('windows-1252', $result->charsetUsed);
        self::assertTrue($result->wasFallback);
        self::assertSame('x-nonexistent-charset-9000', $result->declared, 'and what was asked for is still reported');
    }

    #[Test]
    public function commonAliasesAreCanonicalised(): void
    {
        self::assertSame('UTF-8', $this->converter->canonical('utf8'));
        self::assertSame('UTF-8', $this->converter->canonical('UTF-8'));
        self::assertSame('US-ASCII', $this->converter->canonical('ansi_x3.4-1968'));
        self::assertSame('windows-1251', $this->converter->canonical('CP1251'));
        self::assertSame('', $this->converter->canonical(''), 'nothing declared stays nothing');
    }

    /** RFC 2231 section 5: `utf-8*en` is a charset with a language tag, not a charset called that. */
    #[Test]
    public function aLanguageTagIsStrippedFromTheCharsetName(): void
    {
        self::assertSame('UTF-8', $this->converter->canonical('utf-8*en'));
    }

    #[Test]
    public function quotesAroundACharsetNameAreIgnored(): void
    {
        self::assertSame('UTF-8', $this->converter->canonical('"utf-8"'));
    }

    protected function setUp(): void
    {
        $this->converter = new CharsetConverter();
    }
}
