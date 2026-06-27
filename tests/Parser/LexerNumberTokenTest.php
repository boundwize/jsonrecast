<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Parser;

use Boundwize\JsonRecast\Parser\Lexer;
use Boundwize\JsonRecast\Parser\ParseError;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LexerNumberTokenTest extends TestCase
{
    public function testNumberTokenRejectsInvalidStartingCharacter(): void
    {
        $lexer           = new Lexer();
        $reflectionClass = new ReflectionClass($lexer);

        $reflectionClass->getProperty('source')->setValue($lexer, '+');
        $reflectionClass->getProperty('length')->setValue($lexer, 1);
        $reflectionClass->getProperty('offset')->setValue($lexer, 0);
        $reflectionClass->getProperty('line')->setValue($lexer, 1);
        $reflectionClass->getProperty('column')->setValue($lexer, 1);
        $reflectionClass->getProperty('previousWasCarriageReturn')->setValue($lexer, false);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Invalid JSON number.');

        $reflectionClass->getMethod('numberToken')->invoke($lexer, 0, 1, 1);
    }
}
