<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node\Helper;

use function preg_match;

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
}
