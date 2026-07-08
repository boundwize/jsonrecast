<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Parser;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Parser\JsonParser;
use Boundwize\JsonRecast\Parser\ParseError;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class JsonParserErrorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidJsonProvider(): iterable
    {
        yield 'empty document' => [''];
        yield 'missing object comma' => ['{"a":1 "b":2}'];
        yield 'missing array comma' => ['[1 2]'];
        yield 'trailing array comma' => ['[1,]'];
        yield 'control character in string' => ["\"\n\""];
        yield 'unterminated string escape' => ['"\\'];
        yield 'invalid string escape' => ['"\x"'];
        yield 'invalid unicode escape' => ['"\u00G0"'];
        yield 'unterminated unicode escape' => ['"\u00'];
        yield 'unterminated string' => ['"unterminated'];
        yield 'negative sign without digit' => ['-'];
        yield 'fraction without digit' => ['1.'];
        yield 'exponent without digit' => ['1e'];
        yield 'exponent sign without digit' => ['1e+'];
        yield 'unpaired unicode surrogate' => ['"\uD800"'];
    }

    #[DataProvider('invalidJsonProvider')]
    public function testItRejectsInvalidJson(string $source): void
    {
        $this->expectException(ParseError::class);

        (new JsonParser())->parse($source);
    }

    public function testItReportsUtf8ErrorColumnsByCharacter(): void
    {
        try {
            (new JsonParser())->parse('{"café": tru}');
        } catch (ParseError $parseError) {
            $this->assertSame('Unexpected character.', $parseError->getMessage());
            $this->assertSame(10, $parseError->offset);
            $this->assertSame(1, $parseError->sourceLine);
            $this->assertSame(10, $parseError->column);

            return;
        }

        $this->fail('Expected parser to reject invalid JSON.');
    }

    public function testItReportsUtf8ErrorColumnsByCharacterWithEmoji(): void
    {
        try {
            (new JsonParser())->parse('{"emoji":"😀", "valid": nul}');
        } catch (ParseError $parseError) {
            $this->assertSame('Unexpected character.', $parseError->getMessage());
            $this->assertSame(26, $parseError->offset);
            $this->assertSame(1, $parseError->sourceLine);
            $this->assertSame(24, $parseError->column);

            return;
        }

        $this->fail('Expected parser to reject invalid JSON.');
    }

    public function testItRejectsJsonThatExceedsMaximumNestingDepth(): void
    {
        $json = str_repeat('[', 512) . '1' . str_repeat(']', 512);

        try {
            (new JsonParser())->parse($json);
        } catch (ParseError $parseError) {
            $this->assertSame('Maximum stack depth exceeded.', $parseError->getMessage());
            $this->assertSame(512, $parseError->offset);
            $this->assertSame(1, $parseError->sourceLine);
            $this->assertSame(513, $parseError->column);

            return;
        }

        $this->fail('Expected parser to reject too deeply nested JSON.');
    }

    public function testMaximumNestingDepthCanBeOverridden(): void
    {
        $json = str_repeat('[', 512) . '1' . str_repeat(']', 512);

        $jsonDocument = (new JsonParser(maximumDepth: 513))->parse($json);

        $this->assertSame($json, $jsonDocument->getAttribute(NodeAttributes::ORIGINAL_TEXT));
    }

    public function testMaximumNestingDepthMustBeGreaterThanZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum depth must be greater than 0.');

        new JsonParser(maximumDepth: 0);
    }

    public function testItParsesJsonAtMaximumNestingBoundary(): void
    {
        $json = str_repeat('[', 511) . '1' . str_repeat(']', 511);

        $jsonDocument = (new JsonParser())->parse($json);

        $this->assertSame($json, $jsonDocument->getAttribute(NodeAttributes::ORIGINAL_TEXT));
    }
}
