<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\Helper\LayoutCoordinateHelper;
use Boundwize\JsonRecast\Node\Helper\StartOffsetHelper;
use Boundwize\JsonRecast\Node\Helper\WhitespaceHelper;

use function array_key_exists;
use function array_splice;
use function count;
use function max;
use function str_contains;

final class ArrayNode extends AbstractNodeJson
{
    /**
     * @param list<ArrayItemNode> $items
     */
    public function __construct(
        public array $items,
        public string $afterOpenBracket = '',
        public string $beforeCloseBracket = '',
    ) {
    }

    public function append(NodeJson $nodeJson): void
    {
        $this->insert(count($this->items), $nodeJson);
    }

    public function insert(int $index, NodeJson $nodeJson): void
    {
        $index                      = $this->normalizeInsertionIndex($index);
        $itemCount                  = count($this->items);
        [$beforeValue, $styleDonor] = $this->layoutForInsertedItem($index);
        $arrayItemNode              = new ArrayItemNode(
            value: $nodeJson,
            beforeValue: $beforeValue,
            afterValue: $this->afterValueForInsertedItem($index),
        );
        $arrayItemNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        $arrayItemNode->setAttribute(NodeAttributes::START_OFFSET, $this->startOffsetForInsertedItem($index));
        LayoutCoordinateHelper::setForNewItem($arrayItemNode, $this, $styleDonor);

        if ($index === 0 && $this->items !== []) {
            $this->items[0]->beforeValue = $this->separatorBeforeDisplacedFirstItem();
            $this->items[0]->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        }

        if ($index === $itemCount && $this->items !== []) {
            $lastIndex = $itemCount - 1;

            $this->items[$lastIndex]->afterValue = $this->separatorAfterValue();
            $this->items[$lastIndex]->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        }

        if ($itemCount === 0) {
            $this->afterOpenBracket   = $beforeValue;
            $this->beforeCloseBracket = $arrayItemNode->afterValue;
        }

        array_splice($this->items, $index, 0, [$arrayItemNode]);
    }

    public function setAt(int $index, NodeJson $nodeJson): bool
    {
        if (! array_key_exists($index, $this->items)) {
            return false;
        }

        $this->items[$index]->value = $nodeJson;
        $this->items[$index]->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);

        return true;
    }

    public function removeAt(int $index): bool
    {
        if (! array_key_exists($index, $this->items)) {
            return false;
        }

        array_splice($this->items, $index, 1);

        if ($this->items === []) {
            $this->afterOpenBracket = $this->beforeCloseBracket;
        } elseif ($index === 0) {
            $this->afterOpenBracket = WhitespaceHelper::openingBeforePromotedItem(
                $this->items[0]->beforeValue,
                $this->afterOpenBracket,
            );
        }

        $this->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);

        return true;
    }

    private function normalizeInsertionIndex(int $index): int
    {
        $itemCount = count($this->items);

        if ($index < 0) {
            return max($itemCount + $index, 0);
        }

        if ($index > $itemCount) {
            return $itemCount;
        }

        return $index;
    }

    /**
     * @return array{string, ?ArrayItemNode}
     */
    private function layoutForInsertedItem(int $index): array
    {
        if ($index === 0) {
            if (
                $this->items === []
                && (str_contains($this->afterOpenBracket, "\n") || str_contains($this->afterOpenBracket, "\r"))
            ) {
                return [$this->afterOpenBracket . $this->indentForNewItem(), null];
            }

            return [$this->afterOpenBracket, null];
        }

        if (array_key_exists($index, $this->items)) {
            $styleDonor = $this->items[$index];

            return [$styleDonor->beforeValue, $styleDonor];
        }

        return $this->layoutForAppendedItem();
    }

    private function afterValueForInsertedItem(int $index): string
    {
        $itemCount = count($this->items);

        if ($index === $itemCount) {
            if ($itemCount === 0) {
                if ($this->afterOpenBracket === $this->beforeCloseBracket) {
                    return WhitespaceHelper::closingLine($this->beforeCloseBracket);
                }

                return $this->beforeCloseBracket;
            }

            return $this->items[$itemCount - 1]->afterValue;
        }

        return $this->separatorAfterValue();
    }

    private function separatorAfterValue(): string
    {
        $itemCount = count($this->items);

        if ($itemCount > 1) {
            return $this->items[$itemCount - 2]->afterValue;
        }

        return '';
    }

    /**
     * The first item displaced by a prepend becomes the second item, so it
     * inherits the nearest inter-item whitespace: the separator before the
     * original second item, not the style of the physically last item.
     */
    private function separatorBeforeDisplacedFirstItem(): string
    {
        if (count($this->items) > 1) {
            return $this->items[1]->beforeValue;
        }

        [$beforeValue] = $this->layoutForAppendedItem();

        return $beforeValue;
    }

    /**
     * @return array{string, ArrayItemNode}
     */
    private function layoutForAppendedItem(): array
    {
        $itemCount   = count($this->items);
        $styleDonor  = StartOffsetHelper::findStyleDonor($this->items) ?? $this->items[$itemCount - 1];
        $beforeValue = WhitespaceHelper::separatorAfterOpening($styleDonor->beforeValue, $this->afterOpenBracket);

        if ($beforeValue !== '' || $itemCount > 1) {
            return [$beforeValue, $styleDonor];
        }

        // Single item at position 0: beforeValue equals afterOpenBracket ('' for inline).
        // A new item needs the separator space, so default to ' '.
        return [' ', $styleDonor];
    }

    private function startOffsetForInsertedItem(int $index): float
    {
        $previousOffset = null;

        for ($i = $index - 1; $i >= 0; $i--) {
            $previousOffset = StartOffsetHelper::getNumericStartOffset($this->items[$i]);

            if ($previousOffset !== null) {
                break;
            }
        }

        $nextOffset = null;
        $itemCount  = count($this->items);

        for ($i = $index; $i < $itemCount; $i++) {
            $nextOffset = StartOffsetHelper::getNumericStartOffset($this->items[$i]);

            if ($nextOffset !== null) {
                break;
            }
        }

        if ($previousOffset !== null && $nextOffset !== null) {
            return ($previousOffset + $nextOffset) / 2;
        }

        if ($previousOffset !== null) {
            $maxStartOffset = $previousOffset;

            for ($i = 0, $itemCount = count($this->items); $i < $itemCount; $i++) {
                $startOffset = StartOffsetHelper::getNumericStartOffset($this->items[$i]);

                if ($startOffset !== null) {
                    $maxStartOffset = max($maxStartOffset, $startOffset);
                }
            }

            return $maxStartOffset + 1;
        }

        if ($nextOffset !== null) {
            return $nextOffset - 1;
        }

        return (float) $index;
    }
}
