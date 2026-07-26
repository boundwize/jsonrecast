<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

use function array_keys;
use function intdiv;
use function max;
use function preg_match;
use function preg_match_all;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;

/**
 * Derives a document's per-level indentation unit from its token stream: the
 * indentation a line gains over the previous line, divided by the container
 * depth increase between them. A line's absolute indentation may span several
 * nesting levels when multiple containers open on one line, so neither the
 * shortest line indentation nor an undivided delta is a reliable unit.
 *
 * @internal
 */
final class IndentDetector
{
    private function __construct()
    {
    }

    /**
     * @param list<Token> $tokens
     */
    public static function detect(array $tokens, string $source): string
    {
        return self::mostCommonUnit(self::nestingIndentUnits($tokens))
            ?? self::shortestIndent(self::lineIndentsBeyondRoot($source))
            ?? '    ';
    }

    /**
     * @param list<Token> $tokens
     * @return list<string>
     */
    private static function nestingIndentUnits(array $tokens): array
    {
        $units = [];

        $depth           = 0;
        $previousIndent  = null;
        $previousDepth   = 0;
        $pendingIndent   = '';
        $awaitingContent = true;

        foreach ($tokens as $token) {
            if ($token->type === TokenType::WHITESPACE) {
                $indent = self::indentAfterLastNewline($token->text);

                if ($indent !== null) {
                    $pendingIndent   = $indent;
                    $awaitingContent = true;
                } elseif ($awaitingContent) {
                    // whitespace opening the document without a newline is the
                    // first line's indentation
                    $pendingIndent = $token->text;
                }

                continue;
            }

            if ($token->type === TokenType::END_OF_FILE) {
                break;
            }

            if ($awaitingContent) {
                // a line opening with a closing delimiter is conventionally
                // indented at the level of the container it closes, one level
                // above the contents the running depth still counts
                $lineDepth = $token->type === TokenType::RIGHT_BRACE || $token->type === TokenType::RIGHT_BRACKET
                    ? $depth - 1
                    : $depth;

                if ($previousIndent !== null) {
                    $unit = self::unitFromDelta($previousIndent, $pendingIndent, $lineDepth - $previousDepth);

                    if ($unit !== null) {
                        $units[] = $unit;
                    }
                }

                $previousIndent  = $pendingIndent;
                $previousDepth   = $lineDepth;
                $awaitingContent = false;
            }

            if ($token->type === TokenType::LEFT_BRACE || $token->type === TokenType::LEFT_BRACKET) {
                $depth++;
            } elseif ($token->type === TokenType::RIGHT_BRACE || $token->type === TokenType::RIGHT_BRACKET) {
                $depth--;
            }
        }

        return $units;
    }

    private static function unitFromDelta(string $previousIndent, string $indent, int $depthIncrease): ?string
    {
        if (
            $depthIncrease < 1
            || strlen($indent) <= strlen($previousIndent)
            || ! str_starts_with($indent, $previousIndent)
        ) {
            return null;
        }

        $delta       = substr($indent, strlen($previousIndent));
        $deltaLength = strlen($delta);

        if ($deltaLength % $depthIncrease !== 0) {
            return null;
        }

        $unit = substr($delta, 0, intdiv($deltaLength, $depthIncrease));

        return str_repeat($unit, $depthIncrease) === $delta ? $unit : null;
    }

    /**
     * The unit most lines agree on wins, so a single misaligned line cannot
     * override an otherwise consistent document; ties keep the unit seen
     * first, which the outermost nesting step produces.
     *
     * @param list<string> $units
     */
    private static function mostCommonUnit(array $units): ?string
    {
        /** @var array<string, int> $unitCounts */
        $unitCounts = [];

        foreach ($units as $unit) {
            $unitCounts[$unit] = ($unitCounts[$unit] ?? 0) + 1;
        }

        $mostCommonUnit  = null;
        $mostCommonCount = 0;

        foreach ($unitCounts as $unit => $count) {
            if ($count > $mostCommonCount) {
                $mostCommonUnit  = (string) $unit;
                $mostCommonCount = $count;
            }
        }

        return $mostCommonUnit;
    }

    private static function indentAfterLastNewline(string $whitespace): ?string
    {
        $lineFeedPosition       = strrpos($whitespace, "\n");
        $carriageReturnPosition = strrpos($whitespace, "\r");

        $lastNewlinePosition = max(
            $lineFeedPosition === false ? -1 : $lineFeedPosition,
            $carriageReturnPosition === false ? -1 : $carriageReturnPosition,
        );

        if ($lastNewlinePosition < 0) {
            return null;
        }

        return substr($whitespace, $lastNewlinePosition + 1);
    }

    /**
     * @return list<string>
     */
    private static function lineIndentsBeyondRoot(string $source): array
    {
        preg_match_all('/(?:\r\n|\r|\n)([ \t]+)(?=\S)/', $source, $matches);

        $rootIndent = self::rootIndent($source);

        /** @var array<string, true> $lineIndents */
        $lineIndents = [];

        foreach ($matches[1] as $lineIndent) {
            // the root value's own indentation is the document base, not an indent unit;
            // only the extra whitespace beyond it reveals the per-level indentation
            if (str_starts_with($lineIndent, $rootIndent)) {
                $lineIndent = substr($lineIndent, strlen($rootIndent));
            }

            if ($lineIndent === '') {
                continue;
            }

            $lineIndents[$lineIndent] = true;
        }

        return array_keys($lineIndents);
    }

    private static function rootIndent(string $source): string
    {
        preg_match('/^(?:[ \t]*\R)*([ \t]*)/', $source, $matches);

        return $matches[1] ?? '';
    }

    /**
     * @param list<string> $lineIndents
     */
    private static function shortestIndent(array $lineIndents): ?string
    {
        $indent = null;

        foreach ($lineIndents as $lineIndent) {
            if ($indent === null || strlen($lineIndent) < strlen($indent)) {
                $indent = $lineIndent;
            }
        }

        return $indent;
    }
}
