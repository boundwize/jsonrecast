<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

use Boundwize\JsonRecast\Attribute\NodeAttributes;

use function array_pop;
use function array_splice;
use function count;

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
        $itemCount = count($this->items);
        $lastItem  = $itemCount > 0 ? $this->items[$itemCount - 1] : null;
        $newItem   = new ObjectItemNode(
            key: new StringNode($key),
            value: $nodeJson,
            beforeKey: $this->beforeKeyForAppendedItem(),
            betweenKeyAndColon: $lastItem instanceof ObjectItemNode ? $lastItem->betweenKeyAndColon : '',
            betweenColonAndValue: $lastItem instanceof ObjectItemNode ? $lastItem->betweenColonAndValue : '',
            afterValue: $lastItem instanceof ObjectItemNode ? $lastItem->afterValue : $this->beforeCloseBrace,
        );
        $newItem->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);

        if ($lastItem instanceof ObjectItemNode) {
            $lastItem->afterValue = $this->separatorAfterValue();
            $lastItem->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        }

        $this->items[] = $newItem;
    }

    private function beforeKeyForAppendedItem(): string
    {
        $itemCount = count($this->items);

        if ($itemCount > 1) {
            return $this->items[$itemCount - 1]->beforeKey;
        }

        return $this->afterOpenBrace;
    }

    private function separatorAfterValue(): string
    {
        $itemCount = count($this->items);

        if ($itemCount > 1) {
            return $this->items[$itemCount - 2]->afterValue;
        }

        return '';
    }
}
