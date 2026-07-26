<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node\Helper;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;

use function count;
use function is_int;
use function is_string;
use function preg_match;
use function str_contains;
use function str_replace;
use function strlen;
use function substr;

/**
 * @internal
 */
final readonly class WhitespaceHelper
{
    public static function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    public static function closingLine(string $whitespace): string
    {
        if (preg_match('/(?:\r\n|\r|\n)[^\r\n]*\z/', $whitespace, $matches) === 1) {
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
     * @param list<ArrayItemNode|ObjectItemNode> $items
     */
    public static function normalizeAfterValuesForAppend(array $items): void
    {
        $lastIndex = count($items) - 1;

        if ($lastIndex < 0) {
            return;
        }

        $lastItem            = $items[$lastIndex];
        $closingDonor        = StartOffsetHelper::findStyleDonor($items);
        $separatorAfterValue = self::separatorAfterValue($items, $closingDonor);

        $lastItem->afterValue = $separatorAfterValue;
        $lastItem->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);

        if (
            ($closingDonor instanceof ArrayItemNode || $closingDonor instanceof ObjectItemNode)
            && $closingDonor !== $lastItem
            && $closingDonor->afterValue !== $separatorAfterValue
        ) {
            $closingDonor->afterValue = $separatorAfterValue;
            $closingDonor->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        }
    }

    /**
     * @param list<ArrayItemNode|ObjectItemNode> $items
     */
    private static function separatorAfterValue(
        array $items,
        ArrayItemNode|ObjectItemNode|null $closingDonor,
    ): string {
        if ($closingDonor !== null) {
            $separatorCandidates = [];

            foreach ($items as $item) {
                if ($item === $closingDonor) {
                    continue;
                }

                $isSyntheticClosingCopy = ! is_int($item->getAttribute(NodeAttributes::START_OFFSET))
                    && ! is_string($item->getAttribute(NodeAttributes::ORIGINAL_TEXT))
                    && $item->afterValue === $closingDonor->afterValue;

                if ($isSyntheticClosingCopy) {
                    continue;
                }

                $separatorCandidates[] = $item;
            }

            $separatorDonor = StartOffsetHelper::findStyleDonor($separatorCandidates);

            if ($separatorDonor instanceof ArrayItemNode || $separatorDonor instanceof ObjectItemNode) {
                return $separatorDonor->afterValue;
            }
        }

        $itemCount = count($items);

        if ($itemCount > 1) {
            return $items[$itemCount - 2]->afterValue;
        }

        return '';
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
