<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Parser;

use Boundwize\JsonRecast\Parser\JsonParser;
use Boundwize\JsonRecast\Parser\ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
}
