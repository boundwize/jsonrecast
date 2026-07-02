<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use JsonException;

use function count;
use function is_string;
use function json_decode;
use function str_contains;
use function str_ends_with;
use function strlen;
use function substr;

use const JSON_THROW_ON_ERROR;

final class JsonParser
{
    private string $source = '';

    /** @var list<Token> */
    private array $tokens = [];

    private int $position = 0;

    public function parse(string $source): JsonDocument
    {
        $this->source   = $source;
        $this->tokens   = (new Lexer())->tokenize($source);
        $this->position = 0;

        $beforeValue = $this->readWhitespace();

        $nodeJson = $this->parseValue();

        $afterValue = $this->readWhitespace();
        $this->consume(TokenType::END_OF_FILE);

        $jsonDocument = new JsonDocument($nodeJson, $beforeValue, $afterValue);
        $this->setSourceMetadata($jsonDocument, 0, strlen($source));
        $jsonDocument->setAttribute(NodeAttributes::SOURCE, $source);
        $jsonDocument->setAttribute(NodeAttributes::NEWLINE, $this->detectNewline($source));
        $jsonDocument->setAttribute(NodeAttributes::TRAILING_NEWLINE, $this->hasTrailingNewline($source));

        return $jsonDocument;
    }

    private function parseValue(): NodeJson
    {
        return match ($this->currentToken()->type) {
            TokenType::LEFT_BRACE => $this->parseObject(),
            TokenType::LEFT_BRACKET => $this->parseArray(),
            TokenType::STRING => $this->parseString(),
            TokenType::NUMBER => $this->parseNumber(),
            TokenType::TRUE => $this->parseTrue(),
            TokenType::FALSE => $this->parseFalse(),
            TokenType::NULL => $this->parseNull(),
            default => throw $this->unexpectedToken('JSON value'),
        };
    }

    private function parseObject(): ObjectNode
    {
        $token = $this->consume(TokenType::LEFT_BRACE);

        $beforeKeyStart = $this->currentToken()->startOffset;
        $beforeKey      = $this->readWhitespace();

        if ($this->currentToken()->type === TokenType::RIGHT_BRACE) {
            $close = $this->consume(TokenType::RIGHT_BRACE);
            $node  = new ObjectNode([], afterOpenBrace: $beforeKey, beforeCloseBrace: $beforeKey);
            $this->setSourceMetadata($node, $token->startOffset, $close->endOffset);

            return $node;
        }

        $items = [];

        while (true) {
            $key = $this->parseString();

            $betweenKeyAndColon = $this->readWhitespace();
            $this->consume(TokenType::COLON);
            $betweenColonAndValue = $this->readWhitespace();
            $value                = $this->parseValue();
            $afterValue           = $this->readWhitespace();
            $itemEnd              = $this->currentToken()->startOffset;

            $item = new ObjectItemNode(
                key: $key,
                value: $value,
                beforeKey: $beforeKey,
                betweenKeyAndColon: $betweenKeyAndColon,
                betweenColonAndValue: $betweenColonAndValue,
                afterValue: $afterValue,
            );
            $this->setSourceMetadata($item, $beforeKeyStart, $itemEnd);
            $items[] = $item;

            if ($this->currentToken()->type === TokenType::COMMA) {
                $this->consume(TokenType::COMMA);
                $beforeKeyStart = $this->currentToken()->startOffset;
                $beforeKey      = $this->readWhitespace();

                if ($this->currentToken()->type === TokenType::RIGHT_BRACE) {
                    throw $this->unexpectedToken('object key');
                }

                continue;
            }

            if ($this->currentToken()->type === TokenType::RIGHT_BRACE) {
                $close = $this->consume(TokenType::RIGHT_BRACE);
                $node  = new ObjectNode($items, $items[0]->beforeKey, $afterValue);
                $this->setSourceMetadata($node, $token->startOffset, $close->endOffset);

                return $node;
            }

            throw $this->unexpectedToken('"," or "}"');
        }
    }

    private function parseArray(): ArrayNode
    {
        $token = $this->consume(TokenType::LEFT_BRACKET);

        $beforeValueStart = $this->currentToken()->startOffset;
        $beforeValue      = $this->readWhitespace();

        if ($this->currentToken()->type === TokenType::RIGHT_BRACKET) {
            $close = $this->consume(TokenType::RIGHT_BRACKET);
            $node  = new ArrayNode([], $beforeValue, $beforeValue);
            $this->setSourceMetadata($node, $token->startOffset, $close->endOffset);

            return $node;
        }

        $items = [];

        while (true) {
            $value      = $this->parseValue();
            $afterValue = $this->readWhitespace();
            $itemEnd    = $this->currentToken()->startOffset;
            $items[]    = $this->arrayItem($value, $beforeValue, $afterValue, $beforeValueStart, $itemEnd);

            if ($this->currentToken()->type === TokenType::COMMA) {
                $this->consume(TokenType::COMMA);
                $beforeValueStart = $this->currentToken()->startOffset;
                $beforeValue      = $this->readWhitespace();

                if ($this->currentToken()->type === TokenType::RIGHT_BRACKET) {
                    throw $this->unexpectedToken('array value');
                }

                continue;
            }

            if ($this->currentToken()->type === TokenType::RIGHT_BRACKET) {
                $close = $this->consume(TokenType::RIGHT_BRACKET);
                $node  = new ArrayNode($items, $items[0]->beforeValue, $afterValue);
                $this->setSourceMetadata($node, $token->startOffset, $close->endOffset);

                return $node;
            }

            throw $this->unexpectedToken('"," or "]"');
        }
    }

    private function parseString(): StringNode
    {
        $token = $this->consume(TokenType::STRING);

        try {
            $value = json_decode($token->text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new ParseError($jsonException->getMessage(), $token->startOffset, $token->line, $token->column);
        }

        if (! is_string($value)) {
            throw new ParseError('Expected JSON string.', $token->startOffset, $token->line, $token->column);
        }

        $stringNode = new StringNode($value);
        $this->setSourceMetadata($stringNode, $token->startOffset, $token->endOffset);

        return $stringNode;
    }

    private function parseNumber(): NumberNode
    {
        $token      = $this->consume(TokenType::NUMBER);
        $numberNode = new NumberNode($token->text);
        $this->setSourceMetadata($numberNode, $token->startOffset, $token->endOffset);

        return $numberNode;
    }

    private function parseTrue(): BooleanNode
    {
        $token       = $this->consume(TokenType::TRUE);
        $booleanNode = new BooleanNode(true);
        $this->setSourceMetadata($booleanNode, $token->startOffset, $token->endOffset);

        return $booleanNode;
    }

    private function parseFalse(): BooleanNode
    {
        $token       = $this->consume(TokenType::FALSE);
        $booleanNode = new BooleanNode(false);
        $this->setSourceMetadata($booleanNode, $token->startOffset, $token->endOffset);

        return $booleanNode;
    }

    private function parseNull(): NullNode
    {
        $token    = $this->consume(TokenType::NULL);
        $nullNode = new NullNode();
        $this->setSourceMetadata($nullNode, $token->startOffset, $token->endOffset);

        return $nullNode;
    }

    private function arrayItem(
        NodeJson $nodeJson,
        string $beforeValue,
        string $afterValue,
        int $startOffset,
        int $endOffset,
    ): ArrayItemNode {
        $arrayItemNode = new ArrayItemNode($nodeJson, $beforeValue, $afterValue);
        $this->setSourceMetadata($arrayItemNode, $startOffset, $endOffset);

        return $arrayItemNode;
    }

    private function readWhitespace(): string
    {
        $whitespace = '';

        while ($this->currentToken()->type === TokenType::WHITESPACE) {
            $whitespace .= $this->currentToken()->text;
            $this->position++;
        }

        return $whitespace;
    }

    /**
     * @param TokenType::* $tokenType
     */
    private function consume(string $tokenType): Token
    {
        $token = $this->currentToken();

        if ($token->type !== $tokenType) {
            throw $this->unexpectedToken($tokenType);
        }

        $this->position++;

        return $token;
    }

    /**
     * @phpstan-impure
     */
    private function currentToken(): Token
    {
        return $this->tokens[$this->position] ?? $this->tokens[count($this->tokens) - 1];
    }

    private function unexpectedToken(string $expected): ParseError
    {
        $token = $this->currentToken();

        return new ParseError(
            'Expected ' . $expected . ', got ' . $token->type . '.',
            $token->startOffset,
            $token->line,
            $token->column,
        );
    }

    private function setSourceMetadata(NodeJson $nodeJson, int $startOffset, int $endOffset): void
    {
        $nodeJson->setAttribute(NodeAttributes::START_OFFSET, $startOffset);
        $nodeJson->setAttribute(NodeAttributes::END_OFFSET, $endOffset);
        $nodeJson->setAttribute(
            NodeAttributes::ORIGINAL_TEXT,
            substr($this->source, $startOffset, $endOffset - $startOffset),
        );
    }

    private function detectNewline(string $source): string
    {
        if (str_contains($source, "\r\n")) {
            return "\r\n";
        }

        if (str_contains($source, "\r")) {
            return "\r";
        }

        return "\n";
    }

    private function hasTrailingNewline(string $source): bool
    {
        return str_ends_with($source, "\n") || str_ends_with($source, "\r");
    }
}
