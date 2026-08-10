<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Guard\MaximumDepthGuard;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\Helper\WhitespaceHelper;
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
use function str_ends_with;
use function strlen;
use function strspn;
use function substr;

use const JSON_THROW_ON_ERROR;

final class JsonParser
{
    public const DEFAULT_MAXIMUM_DEPTH = MaximumDepthGuard::DEFAULT_MAXIMUM_DEPTH;

    private string $source = '';

    private string $indent = '    ';

    private string $newline = "\n";

    /** @var list<Token> */
    private array $tokens = [];

    private int $position = 0;

    /** @var positive-int */
    private readonly int $maximumDepth;

    public function __construct(int $maximumDepth = self::DEFAULT_MAXIMUM_DEPTH)
    {
        $this->maximumDepth = MaximumDepthGuard::validateMaximumDepth($maximumDepth);
    }

    public function parse(string $source): JsonDocument
    {
        $this->source   = $source;
        $this->tokens   = (new Lexer())->tokenize($source);
        $this->position = 0;
        $this->indent   = IndentDetector::detect($this->tokens);
        $this->newline  = WhitespaceHelper::detectNewline($source);

        $beforeValue = $this->readWhitespace();

        $nodeJson = $this->parseValue(0);

        $afterValue = $this->readWhitespace();
        $this->consume(TokenType::END_OF_FILE);

        $jsonDocument = new JsonDocument($nodeJson, $beforeValue, $afterValue);
        $this->setSourceMetadata($jsonDocument, 0, strlen($source), 0);
        $jsonDocument->setAttribute(NodeAttributes::SOURCE, $source);
        $jsonDocument->setAttribute(NodeAttributes::NEWLINE, $this->newline);
        $jsonDocument->setAttribute(NodeAttributes::INDENT, $this->indent);
        $jsonDocument->setAttribute(NodeAttributes::TRAILING_NEWLINE, $this->hasTrailingNewline($source));

        return $jsonDocument;
    }

    private function parseValue(int $depth): NodeJson
    {
        $this->guardMaximumDepth($depth);

        return match ($this->currentToken()->type) {
            TokenType::LEFT_BRACE, TokenType::LEFT_BRACKET => $this->parseCollection($depth),
            TokenType::STRING => $this->parseString($depth),
            TokenType::NUMBER => $this->parseNumber($depth),
            TokenType::TRUE => $this->parseBoolean($depth, true),
            TokenType::FALSE => $this->parseBoolean($depth, false),
            TokenType::NULL => $this->parseNull($depth),
            default => throw $this->unexpectedToken('JSON value'),
        };
    }

    private function parseCollection(int $depth): ObjectNode|ArrayNode
    {
        $isObject       = $this->currentToken()->type === TokenType::LEFT_BRACE;
        $openTokenType  = $isObject ? TokenType::LEFT_BRACE : TokenType::LEFT_BRACKET;
        $closeTokenType = $isObject ? TokenType::RIGHT_BRACE : TokenType::RIGHT_BRACKET;
        $token          = $this->consume($openTokenType);

        $beforeItemStart = $this->currentToken()->startOffset;
        $beforeItem      = $this->readWhitespace();

        if ($this->currentToken()->type === $closeTokenType) {
            // json_decode() counts the container itself against the depth limit even
            // when it has no items, so an empty container still occupies $depth + 1
            $this->guardMaximumDepth($depth + 1);

            $close = $this->consume($closeTokenType);
            $node  = $isObject
                ? new ObjectNode([], afterOpenBrace: $beforeItem, beforeCloseBrace: $beforeItem)
                : new ArrayNode([], afterOpenBracket: $beforeItem, beforeCloseBracket: $beforeItem);
            $this->setSourceMetadata(
                $node,
                $token->startOffset,
                $close->endOffset,
                $depth,
                $this->lineIndentationAt($token),
            );

            return $node;
        }

        $expectedItem    = $isObject ? 'object key' : 'array value';
        $expectedClosing = $isObject ? '"," or "}"' : '"," or "]"';
        $objectItems     = [];
        $arrayItems      = [];

        while (true) {
            $itemDepth = $depth + 1;

            if ($isObject) {
                $key                = $this->parseString($itemDepth);
                $betweenKeyAndColon = $this->readWhitespace();
                $this->consume(TokenType::COLON);
                $betweenColonAndValue = $this->readWhitespace();
                $value                = $this->parseValue($itemDepth);
                $afterValue           = $this->readWhitespace();
                $itemEnd              = $this->currentToken()->startOffset;
                $item                 = new ObjectItemNode(
                    key: $key,
                    value: $value,
                    beforeKey: $beforeItem,
                    betweenKeyAndColon: $betweenKeyAndColon,
                    betweenColonAndValue: $betweenColonAndValue,
                    afterValue: $afterValue,
                );
                $this->setSourceMetadata($item, $beforeItemStart, $itemEnd, $itemDepth);
                $objectItems[] = $item;
            } else {
                $value      = $this->parseValue($itemDepth);
                $afterValue = $this->readWhitespace();
                $itemEnd    = $this->currentToken()->startOffset;
                $item       = new ArrayItemNode($value, $beforeItem, $afterValue);
                $this->setSourceMetadata($item, $beforeItemStart, $itemEnd, $itemDepth);
                $arrayItems[] = $item;
            }

            if ($this->currentToken()->type === TokenType::COMMA) {
                $this->consume(TokenType::COMMA);
                $beforeItemStart = $this->currentToken()->startOffset;
                $beforeItem      = $this->readWhitespace();

                if ($this->currentToken()->type === $closeTokenType) {
                    throw $this->unexpectedToken($expectedItem);
                }

                continue;
            }

            if ($this->currentToken()->type === $closeTokenType) {
                $close = $this->consume($closeTokenType);
                $node  = $isObject
                    ? new ObjectNode($objectItems, $objectItems[0]->beforeKey, $afterValue)
                    : new ArrayNode($arrayItems, $arrayItems[0]->beforeValue, $afterValue);
                $this->setSourceMetadata(
                    $node,
                    $token->startOffset,
                    $close->endOffset,
                    $depth,
                    $this->lineIndentationAt($token),
                );

                return $node;
            }

            throw $this->unexpectedToken($expectedClosing);
        }
    }

    private function parseString(int $depth): StringNode
    {
        $token = $this->consume(TokenType::STRING);

        try {
            $value = json_decode($token->text, true, $this->maximumDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new ParseError($jsonException->getMessage(), $token->startOffset, $token->line, $token->column);
        }

        if (! is_string($value)) {
            throw new ParseError('Expected JSON string.', $token->startOffset, $token->line, $token->column);
        }

        $stringNode = new StringNode($value);
        $this->setSourceMetadata($stringNode, $token->startOffset, $token->endOffset, $depth);

        return $stringNode;
    }

    private function parseNumber(int $depth): NumberNode
    {
        $token      = $this->consume(TokenType::NUMBER);
        $numberNode = new NumberNode($token->text);
        $this->setSourceMetadata($numberNode, $token->startOffset, $token->endOffset, $depth);

        return $numberNode;
    }

    private function parseBoolean(int $depth, bool $value): BooleanNode
    {
        $token       = $this->consume($value ? TokenType::TRUE : TokenType::FALSE);
        $booleanNode = new BooleanNode($value);
        $this->setSourceMetadata($booleanNode, $token->startOffset, $token->endOffset, $depth);

        return $booleanNode;
    }

    private function parseNull(int $depth): NullNode
    {
        $token    = $this->consume(TokenType::NULL);
        $nullNode = new NullNode();
        $this->setSourceMetadata($nullNode, $token->startOffset, $token->endOffset, $depth);

        return $nullNode;
    }

    private function readWhitespace(): string
    {
        $token = $this->currentToken();

        if ($token->type !== TokenType::WHITESPACE) {
            return '';
        }

        $this->position++;

        return $token->text;
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

    private function guardMaximumDepth(int $depth): void
    {
        if (! MaximumDepthGuard::isExceeded($this->maximumDepth, $depth)) {
            return;
        }

        $token = $this->currentToken();

        throw new ParseError(
            MaximumDepthGuard::EXCEEDED_MESSAGE,
            $token->startOffset,
            $token->line,
            $token->column,
        );
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

    private function setSourceMetadata(
        NodeJson $nodeJson,
        int $startOffset,
        int $endOffset,
        int $depth,
        ?string $lineIndentation = null,
    ): void {
        $nodeJson->setAttribute(NodeAttributes::START_OFFSET, $startOffset);
        $nodeJson->setAttribute(NodeAttributes::END_OFFSET, $endOffset);
        $nodeJson->setAttribute(NodeAttributes::DEPTH, $depth);
        $nodeJson->setAttribute(NodeAttributes::INDENT, $this->indent);

        if ($lineIndentation !== null) {
            $nodeJson->setAttribute(NodeAttributes::OPENING_LINE_INDENTATION, $lineIndentation);
        }

        $nodeJson->setAttribute(NodeAttributes::NEWLINE, $this->newline);
        $nodeJson->setAttribute(
            NodeAttributes::ORIGINAL_TEXT,
            substr($this->source, $startOffset, $endOffset - $startOffset),
        );
    }

    private function lineIndentationAt(Token $token): string
    {
        $linePrefix = substr(
            $this->source,
            $token->lineStartOffset,
            $token->startOffset - $token->lineStartOffset,
        );

        return substr($linePrefix, 0, strspn($linePrefix, " \t"));
    }

    private function hasTrailingNewline(string $source): bool
    {
        return str_ends_with($source, "\n") || str_ends_with($source, "\r");
    }
}
