<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\Helper\StartOffsetHelper;
use Boundwize\JsonRecast\Node\Helper\WhitespaceHelper;

use function array_pop;
use function array_splice;
use function count;
use function is_string;
use function max;
use function str_contains;

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
            if ($this->items === []) {
                $this->afterOpenBrace = $this->beforeCloseBrace;
            }

            $this->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        }

        return $removed;
    }

    private function appendNewItem(string $key, NodeJson $nodeJson): void
    {
        $itemCount  = count($this->items);
        $lastItem   = $itemCount > 0 ? $this->items[$itemCount - 1] : null;
        $styleDonor = StartOffsetHelper::findStyleDonor($this->items) ?? $lastItem;
        $beforeKey  = $this->beforeKeyForAppendedItem();
        $afterValue = $styleDonor !== null ? $styleDonor->afterValue : $this->beforeCloseBrace;

        if (
            $styleDonor === null
            && $this->afterOpenBrace === $this->beforeCloseBrace
        ) {
            $afterValue = WhitespaceHelper::closingLine($this->beforeCloseBrace);
        }

        $objectItemNode = new ObjectItemNode(
            key: new StringNode($key),
            value: $nodeJson,
            beforeKey: $beforeKey,
            betweenKeyAndColon: $styleDonor !== null ? $styleDonor->betweenKeyAndColon : '',
            betweenColonAndValue: $this->betweenColonAndValueForAppendedItem($styleDonor),
            afterValue: $afterValue,
        );
        $objectItemNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        $objectItemNode->setAttribute(NodeAttributes::START_OFFSET, $this->startOffsetForAppendedItem());

        if ($lastItem instanceof ObjectItemNode) {
            $lastItem->afterValue = $this->separatorAfterValue();
            $lastItem->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        } else {
            $this->afterOpenBrace   = $beforeKey;
            $this->beforeCloseBrace = $afterValue;
        }

        $this->items[] = $objectItemNode;
    }

    private function betweenColonAndValueForAppendedItem(?ObjectItemNode $objectItemNode): string
    {
        if (! $objectItemNode instanceof ObjectItemNode) {
            return ' ';
        }

        if (
            $objectItemNode->betweenColonAndValue === ''
            && ! $objectItemNode->hasAttribute(NodeAttributes::START_OFFSET)
            && ! is_string($objectItemNode->getAttribute(NodeAttributes::ORIGINAL_TEXT))
        ) {
            return ' ';
        }

        return $objectItemNode->betweenColonAndValue;
    }

    private function beforeKeyForAppendedItem(): string
    {
        $itemCount = count($this->items);

        if ($itemCount > 1) {
            $styleDonor = StartOffsetHelper::findStyleDonor($this->items) ?? $this->items[$itemCount - 1];

            return WhitespaceHelper::separatorAfterOpening($styleDonor->beforeKey, $this->afterOpenBrace);
        }

        if ($itemCount === 1) {
            $firstItemBeforeKey = WhitespaceHelper::separatorAfterOpening(
                $this->items[0]->beforeKey,
                $this->afterOpenBrace,
            );

            return $firstItemBeforeKey !== '' ? $firstItemBeforeKey : ' ';
        }

        if (str_contains($this->afterOpenBrace, "\n") || str_contains($this->afterOpenBrace, "\r")) {
            return $this->afterOpenBrace . $this->indentForNewItem();
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

    private function startOffsetForAppendedItem(): float
    {
        $maxStartOffset = null;

        for ($i = count($this->items) - 1; $i >= 0; $i--) {
            $startOffset = StartOffsetHelper::getNumericStartOffset($this->items[$i]);

            if ($startOffset !== null) {
                $maxStartOffset = $maxStartOffset === null ? $startOffset : max($maxStartOffset, $startOffset);
            }
        }

        if ($maxStartOffset !== null) {
            return $maxStartOffset + 1;
        }

        return (float) count($this->items);
    }
}
