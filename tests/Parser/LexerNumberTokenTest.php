<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Parser;

use Boundwize\JsonRecast\Parser\Lexer;
use Boundwize\JsonRecast\Parser\ParseError;
use Boundwize\JsonRecast\Parser\TokenType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LexerNumberTokenTest extends TestCase
{
    public function testNumberTokenAdvancesCursorToFollowingToken(): void
    {
        $tokens = (new Lexer())->tokenize("\r123.45e+6,");

        $this->assertSame(TokenType::NUMBER, $tokens[1]->type);
        $this->assertSame('123.45e+6', $tokens[1]->text);
        $this->assertSame(1, $tokens[1]->startOffset);
        $this->assertSame(10, $tokens[1]->endOffset);
        $this->assertSame(2, $tokens[1]->line);
        $this->assertSame(1, $tokens[1]->column);
        $this->assertSame(TokenType::COMMA, $tokens[2]->type);
        $this->assertSame(2, $tokens[2]->line);
        $this->assertSame(10, $tokens[2]->column);
    }

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
