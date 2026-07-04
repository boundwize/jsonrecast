<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\Helper\StartOffsetHelper;

use function array_key_exists;
use function array_splice;
use function count;
use function max;

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
        $index         = $this->normalizeInsertionIndex($index);
        $itemCount     = count($this->items);
        $arrayItemNode = new ArrayItemNode(
            value: $nodeJson,
            beforeValue: $this->beforeValueForInsertedItem($index),
            afterValue: $this->afterValueForInsertedItem($index),
        );
        $arrayItemNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        $arrayItemNode->setAttribute(NodeAttributes::START_OFFSET, $this->startOffsetForInsertedItem($index));

        if ($index === 0 && $this->items !== []) {
            $this->items[0]->beforeValue = $this->beforeValueForAppendedItem();
            $this->items[0]->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        }

        if ($index === $itemCount && $this->items !== []) {
            $lastIndex = $itemCount - 1;

            $this->items[$lastIndex]->afterValue = $this->separatorAfterValue();
            $this->items[$lastIndex]->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
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

    private function beforeValueForInsertedItem(int $index): string
    {
        if ($index === 0) {
            return $this->afterOpenBracket;
        }

        if (array_key_exists($index, $this->items)) {
            return $this->items[$index]->beforeValue;
        }

        return $this->beforeValueForAppendedItem();
    }

    private function afterValueForInsertedItem(int $index): string
    {
        $itemCount = count($this->items);

        if ($index === $itemCount) {
            if ($itemCount === 0) {
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

    private function beforeValueForAppendedItem(): string
    {
        $itemCount  = count($this->items);
        $styleDonor = StartOffsetHelper::findStyleDonor($this->items) ?? $this->items[$itemCount - 1];

        if ($styleDonor->beforeValue !== '' || $itemCount > 1) {
            return $styleDonor->beforeValue;
        }

        // Single item at position 0: beforeValue equals afterOpenBracket ('' for inline).
        // A new item needs the separator space, so default to ' '.
        return ' ';
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
