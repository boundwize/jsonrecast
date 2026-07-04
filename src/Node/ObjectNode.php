<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

use Boundwize\JsonRecast\Attribute\NodeAttributes;

use function array_pop;
use function array_splice;
use function count;
use function is_float;
use function is_int;
use function max;

final class ObjectNode extends AbstractNodeJson
{
    /**
     * @param list<ObjectItemNode> $items
     */
    public function __construct(
        public array $items,
        public string $afterOpenBrace = '',
        public string $beforeCloseBrace = '',
    ) {
    }

    public function get(string $key): ?ObjectItemNode
    {
        for ($i = count($this->items) - 1; $i >= 0; $i--) {
            if ($this->items[$i]->key->value === $key) {
                return $this->items[$i];
            }
        }

        return null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) instanceof ObjectItemNode;
    }

    public function set(string $key, NodeJson $nodeJson): void
    {
        $matchingIndexes = [];

        foreach ($this->items as $i => $item) {
            if ($item->key->value !== $key) {
                continue;
            }

            $matchingIndexes[] = $i;
        }

        $lastIndex = array_pop($matchingIndexes);

        if ($lastIndex === null) {
            $this->appendNewItem($key, $nodeJson);

            return;
        }

        $item = $this->items[$lastIndex];

        for ($i = count($matchingIndexes) - 1; $i >= 0; $i--) {
            array_splice($this->items, $matchingIndexes[$i], 1);
        }

        $item->value = $nodeJson;
        $item->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
    }

    public function remove(string $key): bool
    {
        $removed = false;

        for ($i = count($this->items) - 1; $i >= 0; $i--) {
            if ($this->items[$i]->key->value !== $key) {
                continue;
            }

            array_splice($this->items, $i, 1);
            $removed = true;
        }

        if ($removed) {
            $this->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        }

        return $removed;
    }

    private function appendNewItem(string $key, NodeJson $nodeJson): void
    {
        $itemCount      = count($this->items);
        $lastItem       = $itemCount > 0 ? $this->items[$itemCount - 1] : null;
        $styleDonor     = $this->styleDonorForAppendedItem() ?? $lastItem;
        $objectItemNode = new ObjectItemNode(
            key: new StringNode($key),
            value: $nodeJson,
            beforeKey: $this->beforeKeyForAppendedItem(),
            betweenKeyAndColon: $styleDonor !== null ? $styleDonor->betweenKeyAndColon : '',
            betweenColonAndValue: $styleDonor !== null ? $styleDonor->betweenColonAndValue : '',
            afterValue: $styleDonor !== null ? $styleDonor->afterValue : $this->beforeCloseBrace,
        );
        $objectItemNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        $objectItemNode->setAttribute(NodeAttributes::START_OFFSET, $this->startOffsetForAppendedItem());

        if ($lastItem instanceof ObjectItemNode) {
            $lastItem->afterValue = $this->separatorAfterValue();
            $lastItem->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        }

        $this->items[] = $objectItemNode;
    }

    private function beforeKeyForAppendedItem(): string
    {
        $itemCount = count($this->items);

        if ($itemCount > 1) {
            return ($this->styleDonorForAppendedItem() ?? $this->items[$itemCount - 1])->beforeKey;
        }

        return $this->afterOpenBrace;
    }

    private function styleDonorForAppendedItem(): ?ObjectItemNode
    {
        $styleDonor     = null;
        $maxStartOffset = null;

        foreach ($this->items as $item) {
            $startOffset = $this->getNumericStartOffset($item);

            if ($startOffset === null) {
                continue;
            }

            if ($maxStartOffset === null || $startOffset > $maxStartOffset) {
                $maxStartOffset = $startOffset;
                $styleDonor     = $item;
            }
        }

        return $styleDonor;
    }

    private function separatorAfterValue(): string
    {
        $itemCount = count($this->items);

        if ($itemCount > 1) {
            return $this->items[$itemCount - 2]->afterValue;
        }

        return '';
    }

    private function startOffsetForAppendedItem(): float
    {
        $maxStartOffset = null;

        for ($i = count($this->items) - 1; $i >= 0; $i--) {
            $startOffset = $this->getNumericStartOffset($this->items[$i]);

            if ($startOffset !== null) {
                $maxStartOffset = $maxStartOffset === null ? $startOffset : max($maxStartOffset, $startOffset);
            }
        }

        if ($maxStartOffset !== null) {
            return $maxStartOffset + 1;
        }

        return (float) count($this->items);
    }

    private function getNumericStartOffset(ObjectItemNode $objectItemNode): ?float
    {
        $startOffset = $objectItemNode->getAttribute(NodeAttributes::START_OFFSET);

        if (is_int($startOffset) || is_float($startOffset)) {
            return (float) $startOffset;
        }

        return null;
    }
}
