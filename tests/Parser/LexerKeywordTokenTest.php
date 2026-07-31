<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Parser;

use Boundwize\JsonRecast\Parser\Lexer;
use Boundwize\JsonRecast\Parser\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function strlen;

final class LexerKeywordTokenTest extends TestCase
{
    /**
     * @return iterable<string, array{string, TokenType::*}>
     */
    public static function keywordProvider(): iterable
    {
        yield 'true' => ['true', TokenType::TRUE];
        yield 'false' => ['false', TokenType::FALSE];
        yield 'null' => ['null', TokenType::NULL];
    }

    /**
     * @param TokenType::* $tokenType
     */
    #[DataProvider('keywordProvider')]
    public function testKeywordTokenAdvancesCursorToFollowingToken(string $keyword, string $tokenType): void
    {
        $tokens        = (new Lexer())->tokenize("\r" . $keyword . ',');
        $keywordLength = strlen($keyword);

        $this->assertSame($tokenType, $tokens[1]->type);
        $this->assertSame($keyword, $tokens[1]->text);
        $this->assertSame(1, $tokens[1]->startOffset);
        $this->assertSame(1 + $keywordLength, $tokens[1]->endOffset);
        $this->assertSame(2, $tokens[1]->line);
        $this->assertSame(1, $tokens[1]->column);
        $this->assertSame(TokenType::COMMA, $tokens[2]->type);
        $this->assertSame(2, $tokens[2]->line);
        $this->assertSame(1 + $keywordLength, $tokens[2]->column);
    }
}
