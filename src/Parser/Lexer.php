<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

use function ctype_digit;
use function ctype_xdigit;
use function ord;
use function strlen;
use function substr;

final class Lexer
{
    private const WHITESPACE_CHARS = [' ' => true, "\t" => true, "\n" => true, "\r" => true];

    private const STRING_ESCAPES = [
        '"'  => true,
        '\\' => true,
        '/'  => true,
        'b'  => true,
        'f'  => true,
        'n'  => true,
        'r'  => true,
        't'  => true,
    ];

    private const KEYWORD_TOKENS = [
        'true'  => TokenType::TRUE,
        'false' => TokenType::FALSE,
        'null'  => TokenType::NULL,
    ];

    private int $offset = 0;

    private int $line = 1;

    private int $column = 1;

    private int $lineStartOffset = 0;

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
        $this->lineStartOffset           = 0;
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
            $this->lineStartOffset,
        );

        return $tokens;
    }

    private function keywordOrNumberToken(int $startOffset, int $line, int $column): Token
    {
        $char = $this->currentChar();

        if ($char === '-' || ctype_digit($char)) {
            return $this->numberToken($startOffset, $line, $column);
        }

        $text = match ($char) {
            't' => 'true',
            'f' => 'false',
            'n' => 'null',
            default => null,
        };

        if ($text === null) {
            throw $this->error('Unexpected character.');
        }

        $length = strlen($text);

        if (substr($this->source, $this->offset, $length) !== $text) {
            throw $this->error('Unexpected character.');
        }

        // JSON keywords contain only single-byte ASCII characters and no line
        // breaks, so validation can be followed by a direct cursor update.
        $this->offset                   += $length;
        $this->column                   += $length;
        $this->previousWasCarriageReturn = false;

        return new Token(
            self::KEYWORD_TOKENS[$text],
            $text,
            $startOffset,
            $this->offset,
            $line,
            $column,
            $this->lineStartOffset,
        );
    }

    /**
     * @param TokenType::* $tokenType
     */
    private function singleCharacterToken(string $tokenType, int $startOffset, int $line, int $column): Token
    {
        $text = $this->currentChar();
        $this->advance();

        return new Token($tokenType, $text, $startOffset, $this->offset, $line, $column, $this->lineStartOffset);
    }

    private function whitespaceToken(int $startOffset, int $line, int $column): Token
    {
        $lineStartOffset = $this->lineStartOffset;

        while (! $this->isAtEnd() && isset(self::WHITESPACE_CHARS[$this->currentChar()])) {
            $this->advance();
        }

        return new Token(
            TokenType::WHITESPACE,
            substr($this->source, $startOffset, $this->offset - $startOffset),
            $startOffset,
            $this->offset,
            $line,
            $column,
            $lineStartOffset,
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
                    $this->lineStartOffset,
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

            if (isset(self::STRING_ESCAPES[$escaped])) {
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
        $numberLexemeScanResult = NumberLexemeScanner::scan($this->source, $this->offset);

        // JSON number lexemes contain only single-byte ASCII characters and no
        // line breaks, so the scanner's result can update the cursor directly
        // instead of walking the same characters a second time.
        $consumedLength = $numberLexemeScanResult->endOffset - $this->offset;
        $this->offset   = $numberLexemeScanResult->endOffset;
        $this->column  += $consumedLength;

        if ($consumedLength > 0) {
            $this->previousWasCarriageReturn = false;
        }

        if ($numberLexemeScanResult->errorMessage !== null) {
            throw $this->error($numberLexemeScanResult->errorMessage);
        }

        return new Token(
            TokenType::NUMBER,
            substr($this->source, $startOffset, $consumedLength),
            $startOffset,
            $this->offset,
            $line,
            $column,
            $this->lineStartOffset,
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
            $this->lineStartOffset           = $this->offset;
            $this->previousWasCarriageReturn = true;

            return;
        }

        if ($char === "\n") {
            if (! $this->previousWasCarriageReturn) {
                $this->line++;
            }

            $this->column                    = 1;
            $this->lineStartOffset           = $this->offset;
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
