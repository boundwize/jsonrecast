<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

use function ctype_digit;
use function strlen;

/**
 * Scans the RFC 8259 number grammar, shared between the lexer (which finds
 * where a number token ends inside a larger source) and the printers (which
 * must not emit a NumberNode whose raw value is not a valid JSON number).
 */
final class NumberLexemeScanner
{
    private const EXPONENT_CHARS = ['e' => true, 'E' => true];

    private const SIGN_CHARS = ['+' => true, '-' => true];

    private function __construct()
    {
    }

    public static function isValidLexeme(string $lexeme): bool
    {
        $numberLexemeScanResult = self::scan($lexeme, 0);

        return $numberLexemeScanResult->errorMessage === null
            && $numberLexemeScanResult->endOffset === strlen($lexeme);
    }

    public static function scan(string $source, int $offset): NumberLexemeScanResult
    {
        $length = strlen($source);

        if ($offset < $length && $source[$offset] === '-') {
            $offset++;
        }

        if ($offset >= $length || ! ctype_digit($source[$offset])) {
            return new NumberLexemeScanResult($offset, 'Invalid JSON number.');
        }

        if ($source[$offset] === '0') {
            $offset++;

            if ($offset < $length && ctype_digit($source[$offset])) {
                return new NumberLexemeScanResult($offset, 'Leading zero is not allowed in JSON number.');
            }
        } else {
            while ($offset < $length && ctype_digit($source[$offset])) {
                $offset++;
            }
        }

        if ($offset < $length && $source[$offset] === '.') {
            $offset++;

            if ($offset >= $length || ! ctype_digit($source[$offset])) {
                return new NumberLexemeScanResult($offset, 'Invalid JSON number fraction.');
            }

            while ($offset < $length && ctype_digit($source[$offset])) {
                $offset++;
            }
        }

        if ($offset < $length && isset(self::EXPONENT_CHARS[$source[$offset]])) {
            $offset++;

            if ($offset < $length && isset(self::SIGN_CHARS[$source[$offset]])) {
                $offset++;
            }

            if ($offset >= $length || ! ctype_digit($source[$offset])) {
                return new NumberLexemeScanResult($offset, 'Invalid JSON number exponent.');
            }

            while ($offset < $length && ctype_digit($source[$offset])) {
                $offset++;
            }
        }

        return new NumberLexemeScanResult($offset);
    }
}
