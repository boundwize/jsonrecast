<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Parser;

use Boundwize\JsonRecast\Parser\JsonParser;
use Boundwize\JsonRecast\Parser\ParseError;
use Boundwize\JsonRecast\Parser\Token;
use Boundwize\JsonRecast\Parser\TokenType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class JsonParserInternalStateTest extends TestCase
{
    public function testStringTokenMustDecodeToString(): void
    {
        $jsonParser = new JsonParser();
        $this->setParserState($jsonParser, '123', [
            new Token(TokenType::STRING, '123', 0, 3, 1, 1),
        ]);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Expected JSON string.');

        $this->invokeParserMethod($jsonParser, 'parseString', 0);
    }

    public function testCurrentTokenFallsBackToEndOfFileToken(): void
    {
        $jsonParser = new JsonParser();
        $token      = new Token(TokenType::END_OF_FILE, '', 5, 5, 1, 6);
        $this->setParserState($jsonParser, '', [$token], 10);

        $this->assertSame($token, $this->invokeParserMethod($jsonParser, 'currentToken'));
    }

    public function testConsumeRejectsUnexpectedToken(): void
    {
        $jsonParser = new JsonParser();
        $this->setParserState($jsonParser, '', [
            new Token(TokenType::END_OF_FILE, '', 0, 0, 1, 1),
        ]);

        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Expected String, got EndOfFile.');

        $this->invokeParserMethod($jsonParser, 'consume', TokenType::STRING);
    }

    /**
     * @param list<Token> $tokens
     */
    private function setParserState(JsonParser $jsonParser, string $source, array $tokens, int $position = 0): void
    {
        $reflectionClass = new ReflectionClass($jsonParser);

        $reflectionClass->getProperty('source')->setValue($jsonParser, $source);
        $reflectionClass->getProperty('tokens')->setValue($jsonParser, $tokens);
        $reflectionClass->getProperty('position')->setValue($jsonParser, $position);
    }

    private function invokeParserMethod(JsonParser $jsonParser, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionClass($jsonParser))->getMethod($method)->invoke($jsonParser, ...$arguments);
    }
}
