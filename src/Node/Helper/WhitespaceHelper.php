<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node\Helper;

use function preg_match;
use function str_contains;
use function strlen;
use function substr;

/**
 * @internal
 */
final readonly class WhitespaceHelper
{
    public static function closingLine(string $whitespace): string
    {
        if (preg_match('/(?:\r\n|\r|\n)[^\r\n]*$/', $whitespace, $matches) === 1) {
            return $matches[0];
        }

        return $whitespace;
    }

    /**
     * Whitespace to place before an item appended after $donorWhitespace.
     *
     * When the donor only carries the container's opening whitespace (e.g. a
     * decorative blank line in an otherwise empty container), a following item
     * must reuse just the final indented line and not repeat that one-time
     * opening decoration. Intentional inter-item whitespace, which differs from
     * the opening whitespace, is preserved verbatim.
     */
    public static function separatorAfterOpening(string $donorWhitespace, string $openingWhitespace): string
    {
        if ($donorWhitespace === $openingWhitespace) {
            return self::closingLine($donorWhitespace);
        }

        return $donorWhitespace;
    }

    /**
     * Opening whitespace to use when an existing item is promoted to index 0.
     *
     * A multiline separator belongs to the promoted item's line and must move
     * with it, while any decoration before the opening whitespace's final line
     * (e.g. a decorative blank line) belongs to the container and stays. Inline
     * comma spacing does not belong after the opening delimiter, so the
     * container's existing opening whitespace is retained in that case.
     */
    public static function openingBeforePromotedItem(
        string $itemWhitespace,
        string $openingWhitespace,
    ): string {
        if (! str_contains($itemWhitespace, "\n") && ! str_contains($itemWhitespace, "\r")) {
            return $openingWhitespace;
        }

        $closingLine = self::closingLine($openingWhitespace);

        return substr(
            $openingWhitespace,
            0,
            strlen($openingWhitespace) - strlen($closingLine),
        ) . $itemWhitespace;
    }
}
