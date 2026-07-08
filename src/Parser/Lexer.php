<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

use function ctype_digit;
use function ctype_xdigit;
use function in_array;
use function ord;
use function strlen;
use function substr;

final class Lexer
{
    private int $offset = 0;

    private int $line = 1;

    private int $column = 1;

    private bool $previousWasCarriageReturn = false;

    private string $source = '';

    private int $length = 0;

    /**
     * @return list<Token>
     */
    public function tokenize(string $source): array
    {
        $this->source                    = $source;
        $this->length                    = strlen($source);
        $this->offset                    = 0;
        $this->line                      = 1;
        $this->column                    = 1;
        $this->previousWasCarriageReturn = false;
        $tokens                          = [];

        while (! $this->isAtEnd()) {
            $char        = $this->currentChar();
            $startOffset = $this->offset;
            $line        = $this->line;
            $column      = $this->column;

            $tokens[] = match ($char) {
                '{' => $this->singleCharacterToken(TokenType::LEFT_BRACE, $startOffset, $line, $column),
                '}' => $this->singleCharacterToken(TokenType::RIGHT_BRACE, $startOffset, $line, $column),
                '[' => $this->singleCharacterToken(TokenType::LEFT_BRACKET, $startOffset, $line, $column),
                ']' => $this->singleCharacterToken(TokenType::RIGHT_BRACKET, $startOffset, $line, $column),
                ':' => $this->singleCharacterToken(TokenType::COLON, $startOffset, $line, $column),
                ',' => $this->singleCharacterToken(TokenType::COMMA, $startOffset, $line, $column),
                '"' => $this->stringToken($startOffset, $line, $column),
                ' ', "\t", "\n", "\r" => $this->whitespaceToken($startOffset, $line, $column),
                default => $this->keywordOrNumberToken($startOffset, $line, $column),
            };
        }

        $tokens[] = new Token(
            TokenType::END_OF_FILE,
            '',
            $this->offset,
            $this->offset,
            $this->line,
            $this->column,
        );

        return $tokens;
    }

    private function keywordOrNumberToken(int $startOffset, int $line, int $column): Token
    {
        $char = $this->currentChar();

        if ($char === '-' || ctype_digit($char)) {
            return $this->numberToken($startOffset, $line, $column);
        }

        foreach (
            [
                'true'  => TokenType::TRUE,
                'false' => TokenType::FALSE,
                'null'  => TokenType::NULL,
            ] as $text => $type
        ) {
            if (substr($this->source, $this->offset, strlen($text)) !== $text) {
                continue;
            }

            for ($i = 0; $i < strlen($text); $i++) {
                $this->advance();
            }

            return new Token($type, $text, $startOffset, $this->offset, $line, $column);
        }

        throw $this->error('Unexpected character.');
    }

    /**
     * @param TokenType::* $tokenType
     */
    private function singleCharacterToken(string $tokenType, int $startOffset, int $line, int $column): Token
    {
        $text = $this->currentChar();
        $this->advance();

        return new Token($tokenType, $text, $startOffset, $this->offset, $line, $column);
    }

    private function whitespaceToken(int $startOffset, int $line, int $column): Token
    {
        while (! $this->isAtEnd() && in_array($this->currentChar(), [' ', "\t", "\n", "\r"], true)) {
            $this->advance();
        }

        return new Token(
            TokenType::WHITESPACE,
            substr($this->source, $startOffset, $this->offset - $startOffset),
            $startOffset,
            $this->offset,
            $line,
            $column,
        );
    }

    private function stringToken(int $startOffset, int $line, int $column): Token
    {
        $this->advance();

        while (! $this->isAtEnd()) {
            $char = $this->currentChar();

            if ($char === '"') {
                $this->advance();

                return new Token(
                    TokenType::STRING,
                    substr($this->source, $startOffset, $this->offset - $startOffset),
                    $startOffset,
                    $this->offset,
                    $line,
                    $column,
                );
            }

            if (ord($char) < 0x20) {
                throw $this->error('Control character is not allowed in JSON string.');
            }

            if ($char !== '\\') {
                $this->advance();
                continue;
            }

            $this->advance();

            if ($this->isAtEnd()) {
                throw $this->error('Unterminated JSON string escape.');
            }

            $escaped = $this->currentChar();

            if (in_array($escaped, ['"', '\\', '/', 'b', 'f', 'n', 'r', 't'], true)) {
                $this->advance();
                continue;
            }

            if ($escaped !== 'u') {
                throw $this->error('Invalid JSON string escape.');
            }

            $this->advance();

            for ($i = 0; $i < 4; $i++) {
                if ($this->isAtEnd() || ! ctype_xdigit($this->currentChar())) {
                    throw $this->error('Invalid JSON unicode escape.');
                }

                $this->advance();
            }
        }

        throw $this->error('Unterminated JSON string.');
    }

    private function numberToken(int $startOffset, int $line, int $column): Token
    {
        if ($this->currentChar() === '-') {
            $this->advance();

            if ($this->isAtEnd() || ! ctype_digit($this->currentChar())) {
                throw $this->error('Invalid JSON number.');
            }
        }

        if ($this->currentChar() === '0') {
            $this->advance();

            if (! $this->isAtEnd() && ctype_digit($this->currentChar())) {
                throw $this->error('Leading zero is not allowed in JSON number.');
            }
        } else {
            if (! ctype_digit($this->currentChar()) || $this->currentChar() === '0') {
                throw $this->error('Invalid JSON number.');
            }

            while (! $this->isAtEnd() && ctype_digit($this->currentChar())) {
                $this->advance();
            }
        }

        if (! $this->isAtEnd() && $this->currentChar() === '.') {
            $this->advance();

            if ($this->isAtEnd() || ! ctype_digit($this->currentChar())) {
                throw $this->error('Invalid JSON number fraction.');
            }

            while (! $this->isAtEnd() && ctype_digit($this->currentChar())) {
                $this->advance();
            }
        }

        if (! $this->isAtEnd() && in_array($this->currentChar(), ['e', 'E'], true)) {
            $this->advance();

            if (! $this->isAtEnd() && in_array($this->currentChar(), ['+', '-'], true)) {
                $this->advance();
            }

            if ($this->isAtEnd() || ! ctype_digit($this->currentChar())) {
                throw $this->error('Invalid JSON number exponent.');
            }

            while (! $this->isAtEnd() && ctype_digit($this->currentChar())) {
                $this->advance();
            }
        }

        return new Token(
            TokenType::NUMBER,
            substr($this->source, $startOffset, $this->offset - $startOffset),
            $startOffset,
            $this->offset,
            $line,
            $column,
        );
    }

    private function isAtEnd(): bool
    {
        return $this->offset >= $this->length;
    }

    private function currentChar(): string
    {
        return $this->source[$this->offset];
    }

    private function advance(): void
    {
        $char = $this->source[$this->offset];
        $this->offset++;

        if ($char === "\r") {
            $this->line++;
            $this->column                    = 1;
            $this->previousWasCarriageReturn = true;

            return;
        }

        if ($char === "\n") {
            if (! $this->previousWasCarriageReturn) {
                $this->line++;
            }

            $this->column                    = 1;
            $this->previousWasCarriageReturn = false;

            return;
        }

        $byte = ord($char);

        if ($byte < 0x80 || $byte > 0xBF) {
            $this->column++;
        }

        $this->previousWasCarriageReturn = false;
    }

    private function error(string $message): ParseError
    {
        return new ParseError($message, $this->offset, $this->line, $this->column);
    }
}
