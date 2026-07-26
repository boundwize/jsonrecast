<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

use function intdiv;
use function max;
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
    public static function detect(array $tokens): string
    {
        [$units, $lineIndents] = self::scanLines($tokens);

        return self::mostCommonUnit($units)
            ?? self::shortestIndent($lineIndents)
            ?? '    ';
    }

    /**
     * Walks the token stream once, collecting a per-level unit candidate from
     * each depth increase across a line break, and every line's indentation
     * beyond the root value's own as the fallback for documents whose nesting
     * never deepens across lines.
     *
     * @param list<Token> $tokens
     * @return array{list<string>, list<string>}
     */
    private static function scanLines(array $tokens): array
    {
        $units       = [];
        $lineIndents = [];
        $rootIndent  = null;

        $depth              = 0;
        $previousIndent     = null;
        $previousDepth      = 0;
        $pendingIndent      = '';
        $awaitingContent    = true;
        $lineIndentRecorded = false;

        foreach ($tokens as $token) {
            if ($token->type === TokenType::WHITESPACE) {
                $indent = self::indentAfterLastNewline($token->text);

                if ($indent !== null) {
                    $pendingIndent      = $indent;
                    $awaitingContent    = true;
                    $lineIndentRecorded = false;
                } elseif ($previousIndent === null) {
                    // whitespace opening the document without a newline is the
                    // first line's indentation; once a line is recorded,
                    // newline-less whitespace is mid-line spacing, which must
                    // not clobber the indent saved for a leading closer run
                    $pendingIndent = $token->text;
                }

                continue;
            }

            if ($token->type === TokenType::END_OF_FILE) {
                break;
            }

            if (! $lineIndentRecorded) {
                $lineIndentRecorded = true;

                if ($rootIndent === null) {
                    // the root value's own indentation is the document base,
                    // not an indent unit; only the extra whitespace beyond it
                    // reveals the per-level indentation
                    $rootIndent = $pendingIndent;
                } else {
                    $lineIndent = str_starts_with($pendingIndent, $rootIndent)
                        ? substr($pendingIndent, strlen($rootIndent))
                        : $pendingIndent;

                    if ($lineIndent !== '') {
                        $lineIndents[] = $lineIndent;
                    }
                }
            }

            if ($awaitingContent) {
                // a line's leading run of closing delimiters steps back out of
                // the containers it closes, so the line's effective depth is
                // the depth of the first content after the run; a line holding
                // only closers never pairs with a deeper line and needs no record
                if ($token->type === TokenType::RIGHT_BRACE || $token->type === TokenType::RIGHT_BRACKET) {
                    $depth--;

                    continue;
                }

                if ($previousIndent !== null) {
                    $unit = self::unitFromDelta($previousIndent, $pendingIndent, $depth - $previousDepth);

                    if ($unit !== null) {
                        $units[] = $unit;
                    }
                }

                $previousIndent  = $pendingIndent;
                $previousDepth   = $depth;
                $awaitingContent = false;
            }

            if ($token->type === TokenType::LEFT_BRACE || $token->type === TokenType::LEFT_BRACKET) {
                $depth++;
            } elseif ($token->type === TokenType::RIGHT_BRACE || $token->type === TokenType::RIGHT_BRACKET) {
                $depth--;
            }
        }

        return [$units, $lineIndents];
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
                $mostCommonUnit  = $unit;
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
