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

        $this->readWhitespace();

        $value = $this->parseValue();

        $this->readWhitespace();
        $this->consume(TokenType::EndOfFile);

        $document = new JsonDocument($value);
        $this->setSourceMetadata($document, 0, strlen($source));
        $document->setAttribute(NodeAttributes::SOURCE, $source);
        $document->setAttribute(NodeAttributes::NEWLINE, $this->detectNewline($source));
        $document->setAttribute(NodeAttributes::TRAILING_NEWLINE, $this->hasTrailingNewline($source));

        return $document;
    }

    private function parseValue(): NodeJson
    {
        return match ($this->currentToken()->type) {
            TokenType::LeftBrace => $this->parseObject(),
            TokenType::LeftBracket => $this->parseArray(),
            TokenType::String => $this->parseString(),
            TokenType::Number => $this->parseNumber(),
            TokenType::True => $this->parseTrue(),
            TokenType::False => $this->parseFalse(),
            TokenType::Null => $this->parseNull(),
            default => throw $this->unexpectedToken('JSON value'),
        };
    }

    private function parseObject(): ObjectNode
    {
        $open = $this->consume(TokenType::LeftBrace);

        $beforeKeyStart = $this->currentToken()->startOffset;
        $beforeKey      = $this->readWhitespace();

        if ($this->currentToken()->type === TokenType::RightBrace) {
            $close = $this->consume(TokenType::RightBrace);
            $node  = new ObjectNode([]);
            $this->setSourceMetadata($node, $open->startOffset, $close->endOffset);

            return $node;
        }

        $items = [];

        while (true) {
            $key = $this->parseString();

            $betweenKeyAndColon = $this->readWhitespace();
            $this->consume(TokenType::Colon);
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

            if ($this->currentToken()->type === TokenType::Comma) {
                $this->consume(TokenType::Comma);
                $beforeKeyStart = $this->currentToken()->startOffset;
                $beforeKey      = $this->readWhitespace();

                if ($this->currentToken()->type === TokenType::RightBrace) {
                    throw $this->unexpectedToken('object key');
                }

                continue;
            }

            if ($this->currentToken()->type === TokenType::RightBrace) {
                $close = $this->consume(TokenType::RightBrace);
                $node  = new ObjectNode($items);
                $this->setSourceMetadata($node, $open->startOffset, $close->endOffset);

                return $node;
            }

            throw $this->unexpectedToken('"," or "}"');
        }
    }

    private function parseArray(): ArrayNode
    {
        $open = $this->consume(TokenType::LeftBracket);

        $beforeValueStart = $this->currentToken()->startOffset;
        $beforeValue      = $this->readWhitespace();

        if ($this->currentToken()->type === TokenType::RightBracket) {
            $close = $this->consume(TokenType::RightBracket);
            $node  = new ArrayNode([], $beforeValue, $beforeValue);
            $this->setSourceMetadata($node, $open->startOffset, $close->endOffset);

            return $node;
        }

        $items = [];

        while (true) {
            $value      = $this->parseValue();
            $afterValue = $this->readWhitespace();
            $itemEnd    = $this->currentToken()->startOffset;
            $items[]    = $this->arrayItem($value, $beforeValue, $afterValue, $beforeValueStart, $itemEnd);

            if ($this->currentToken()->type === TokenType::Comma) {
                $this->consume(TokenType::Comma);
                $beforeValueStart = $this->currentToken()->startOffset;
                $beforeValue      = $this->readWhitespace();

                if ($this->currentToken()->type === TokenType::RightBracket) {
                    throw $this->unexpectedToken('array value');
                }

                continue;
            }

            if ($this->currentToken()->type === TokenType::RightBracket) {
                $close = $this->consume(TokenType::RightBracket);
                $node  = new ArrayNode($items, $items[0]->beforeValue, $afterValue);
                $this->setSourceMetadata($node, $open->startOffset, $close->endOffset);

                return $node;
            }

            throw $this->unexpectedToken('"," or "]"');
        }
    }

    private function parseString(): StringNode
    {
        $token = $this->consume(TokenType::String);

        try {
            $value = json_decode($token->text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParseError($exception->getMessage(), $token->startOffset, $token->line, $token->column);
        }

        if (! is_string($value)) {
            throw new ParseError('Expected JSON string.', $token->startOffset, $token->line, $token->column);
        }

        $node = new StringNode($value);
        $this->setSourceMetadata($node, $token->startOffset, $token->endOffset);

        return $node;
    }

    private function parseNumber(): NumberNode
    {
        $token = $this->consume(TokenType::Number);
        $node  = new NumberNode($token->text);
        $this->setSourceMetadata($node, $token->startOffset, $token->endOffset);

        return $node;
    }

    private function parseTrue(): BooleanNode
    {
        $token = $this->consume(TokenType::True);
        $node  = new BooleanNode(true);
        $this->setSourceMetadata($node, $token->startOffset, $token->endOffset);

        return $node;
    }

    private function parseFalse(): BooleanNode
    {
        $token = $this->consume(TokenType::False);
        $node  = new BooleanNode(false);
        $this->setSourceMetadata($node, $token->startOffset, $token->endOffset);

        return $node;
    }

    private function parseNull(): NullNode
    {
        $token = $this->consume(TokenType::Null);
        $node  = new NullNode();
        $this->setSourceMetadata($node, $token->startOffset, $token->endOffset);

        return $node;
    }

    private function arrayItem(
        NodeJson $value,
        string $beforeValue,
        string $afterValue,
        int $startOffset,
        int $endOffset,
    ): ArrayItemNode {
        $item = new ArrayItemNode($value, $beforeValue, $afterValue);
        $this->setSourceMetadata($item, $startOffset, $endOffset);

        return $item;
    }

    private function readWhitespace(): string
    {
        $whitespace = '';

        while ($this->currentToken()->type === TokenType::Whitespace) {
            $whitespace .= $this->currentToken()->text;
            $this->position++;
        }

        return $whitespace;
    }

    private function consume(TokenType $type): Token
    {
        $token = $this->currentToken();

        if ($token->type !== $type) {
            throw $this->unexpectedToken($type->name);
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
            'Expected ' . $expected . ', got ' . $token->type->name . '.',
            $token->startOffset,
            $token->line,
            $token->column,
        );
    }

    private function setSourceMetadata(NodeJson $node, int $startOffset, int $endOffset): void
    {
        $node->setAttribute(NodeAttributes::START_OFFSET, $startOffset);
        $node->setAttribute(NodeAttributes::END_OFFSET, $endOffset);
        $node->setAttribute(
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
