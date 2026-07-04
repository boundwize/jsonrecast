<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeTraverser\NodeChangeSet;
use RuntimeException;

use function count;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function str_ends_with;
use function usort;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class JsonPreservingPrinter implements JsonPrinter
{
    public function __construct(
        private ?NodeChangeSet $nodeChangeSet = null,
        private string $indent = '    ',
    ) {
    }

    public function print(NodeJson $nodeJson): string
    {
        $newline = $nodeJson instanceof JsonDocument && is_string($nodeJson->getAttribute(NodeAttributes::NEWLINE))
            ? $nodeJson->getAttribute(NodeAttributes::NEWLINE)
            : "\n";

        return $this->printNode($nodeJson, new PrintContext($this->indent, $newline));
    }

    private function printNode(
        NodeJson $nodeJson,
        PrintContext $printContext,
        bool $detectScalarMutation = false,
    ): string {
        $detectScalarMutation = $detectScalarMutation || $this->isExplicitlyChanged($nodeJson);

        if (! $this->isChanged($nodeJson)) {
            $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (
                is_string($originalText)
                && (! $detectScalarMutation || ! $this->hasScalarValueChanged($nodeJson))
            ) {
                return $originalText;
            }
        }

        return match (true) {
            $nodeJson instanceof JsonDocument => $this->printDocument($nodeJson, $printContext, $detectScalarMutation),
            $nodeJson instanceof ObjectNode => $this->printObject($nodeJson, $printContext, $detectScalarMutation),
            $nodeJson instanceof ObjectItemNode => $this->printObjectItemPreserving(
                $nodeJson,
                $printContext,
                detectScalarMutation: $detectScalarMutation,
            ),
            $nodeJson instanceof ArrayNode => $this->printArray($nodeJson, $printContext, $detectScalarMutation),
            $nodeJson instanceof ArrayItemNode => $this->printArrayItemPreserving(
                $nodeJson,
                $printContext,
                detectScalarMutation: $detectScalarMutation,
            ),
            $nodeJson instanceof StringNode => $this->encodeString($nodeJson->value),
            $nodeJson instanceof NumberNode => $nodeJson->rawValue,
            $nodeJson instanceof BooleanNode => $nodeJson->value ? 'true' : 'false',
            $nodeJson instanceof NullNode => 'null',
            default => throw new RuntimeException('Unsupported JSON node.'),
        };
    }

    private function printDocument(
        JsonDocument $jsonDocument,
        PrintContext $printContext,
        bool $detectScalarMutation,
    ): string {
        $output = $jsonDocument->beforeValue
            . $this->printNode($jsonDocument->value, $printContext, $detectScalarMutation)
            . $jsonDocument->afterValue;

        if (
            $jsonDocument->getAttribute(NodeAttributes::TRAILING_NEWLINE) === true
            && ! str_ends_with($output, $printContext->newline)
        ) {
            $output .= $printContext->newline;
        }

        return $output;
    }

    private function printObject(
        ObjectNode $objectNode,
        PrintContext $printContext,
        bool $detectScalarMutation,
    ): string {
        if ($this->shouldPrintContainerBestEffort($objectNode, $objectNode->items)) {
            return $this->printObjectBestEffort($objectNode, $printContext, $detectScalarMutation);
        }

        $detectScalarMutation = $detectScalarMutation || $this->isExplicitlyChanged($objectNode);
        $output               = '{';
        $lastIndex            = count($objectNode->items) - 1;
        $itemsInOriginalOrder = $this->getItemsInOriginalOrder($objectNode->items);
        $isReordered          = $itemsInOriginalOrder !== $objectNode->items;

        foreach ($objectNode->items as $i => $item) {
            $beforeKey          = $i === 0 ? $objectNode->afterOpenBrace : null;
            $afterValue         = $i === $lastIndex ? $objectNode->beforeCloseBrace : null;
            $afterValueProvider = $item;

            if ($isReordered) {
                if ($i > 0) {
                    $beforeKey = $itemsInOriginalOrder[$i]->beforeKey;
                }

                if ($i < $lastIndex) {
                    $afterValueProvider = $itemsInOriginalOrder[$i];
                    $afterValue         = $afterValueProvider->afterValue;
                }
            }

            if ($i < $lastIndex) {
                $afterValue = $this->normalizeSyntheticAfterValue(
                    $objectNode->items,
                    $i,
                    $afterValue ?? $item->afterValue,
                    $afterValueProvider,
                    $objectNode->beforeCloseBrace,
                );
            }

            $output .= $this->printObjectItemPreserving(
                $item,
                $printContext->next(),
                $beforeKey,
                $afterValue,
                $detectScalarMutation,
            );

            if ($i < count($objectNode->items) - 1) {
                $output .= ',';
            }
        }

        return $output . '}';
    }

    private function printObjectBestEffort(
        ObjectNode $objectNode,
        PrintContext $printContext,
        bool $detectScalarMutation,
    ): string {
        if ($objectNode->items === []) {
            return $this->printEmptyObject($objectNode);
        }

        $detectScalarMutation = $detectScalarMutation || $this->isExplicitlyChanged($objectNode);
        $output               = '{';

        foreach ($objectNode->items as $i => $item) {
            $output .= $printContext->newline
                . $printContext->childIndentation()
                . $this->printObjectItemBestEffort($item, $printContext->next(), $detectScalarMutation);

            if ($i < count($objectNode->items) - 1) {
                $output .= ',';
            }
        }

        return $output . $printContext->newline . $printContext->indentation() . '}';
    }

    private function printEmptyObject(ObjectNode $objectNode): string
    {
        if ($objectNode->beforeCloseBrace !== '') {
            return '{' . $objectNode->beforeCloseBrace . '}';
        }

        return '{}';
    }

    private function printObjectItemPreserving(
        ObjectItemNode $objectItemNode,
        PrintContext $printContext,
        ?string $beforeKey = null,
        ?string $afterValue = null,
        bool $detectScalarMutation = false,
    ): string {
        $beforeKey          ??= $objectItemNode->beforeKey;
        $afterValue         ??= $objectItemNode->afterValue;
        $detectScalarMutation = $detectScalarMutation || $this->isExplicitlyChanged($objectItemNode);

        if (
            $beforeKey === $objectItemNode->beforeKey
            && $afterValue === $objectItemNode->afterValue
            && ! $this->isChanged($objectItemNode)
            && ! $detectScalarMutation
        ) {
            $originalText = $objectItemNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (is_string($originalText)) {
                return $originalText;
            }
        }

        return $beforeKey
            . $this->printNode($objectItemNode->key, $printContext, $detectScalarMutation)
            . $objectItemNode->betweenKeyAndColon
            . ':'
            . $objectItemNode->betweenColonAndValue
            . $this->printNode($objectItemNode->value, $printContext, $detectScalarMutation)
            . $afterValue;
    }

    private function printObjectItemBestEffort(
        ObjectItemNode $objectItemNode,
        PrintContext $printContext,
        bool $detectScalarMutation,
    ): string {
        return $this->printNode($objectItemNode->key, $printContext, $detectScalarMutation)
            . ': '
            . $this->printNode($objectItemNode->value, $printContext, $detectScalarMutation);
    }

    private function printArray(
        ArrayNode $arrayNode,
        PrintContext $printContext,
        bool $detectScalarMutation,
    ): string {
        if ($this->shouldPrintContainerBestEffort($arrayNode, $arrayNode->items)) {
            return $this->printArrayBestEffort($arrayNode, $printContext, $detectScalarMutation);
        }

        $detectScalarMutation = $detectScalarMutation || $this->isExplicitlyChanged($arrayNode);
        $output               = '[';
        $lastIndex            = count($arrayNode->items) - 1;
        $itemsInOriginalOrder = $this->getItemsInOriginalOrder($arrayNode->items);
        $isReordered          = $itemsInOriginalOrder !== $arrayNode->items;

        foreach ($arrayNode->items as $i => $item) {
            $beforeValue        = $i === 0 ? $arrayNode->afterOpenBracket : null;
            $afterValue         = $i === $lastIndex ? $arrayNode->beforeCloseBracket : null;
            $afterValueProvider = $item;

            if ($isReordered) {
                if ($i > 0) {
                    $beforeValue = $itemsInOriginalOrder[$i]->beforeValue;
                }

                if ($i < $lastIndex) {
                    $afterValueProvider = $itemsInOriginalOrder[$i];
                    $afterValue         = $afterValueProvider->afterValue;
                }
            }

            if ($i < $lastIndex) {
                $afterValue = $this->normalizeSyntheticAfterValue(
                    $arrayNode->items,
                    $i,
                    $afterValue ?? $item->afterValue,
                    $afterValueProvider,
                    $arrayNode->beforeCloseBracket,
                );
            }

            $output .= $this->printArrayItemPreserving(
                $item,
                $printContext->next(),
                $beforeValue,
                $afterValue,
                $detectScalarMutation,
            );

            if ($i < count($arrayNode->items) - 1) {
                $output .= ',';
            }
        }

        return $output . ']';
    }

    private function printArrayBestEffort(
        ArrayNode $arrayNode,
        PrintContext $printContext,
        bool $detectScalarMutation,
    ): string {
        if ($arrayNode->items === []) {
            return $this->printEmptyArray($arrayNode);
        }

        $detectScalarMutation = $detectScalarMutation || $this->isExplicitlyChanged($arrayNode);
        $output               = '[';

        foreach ($arrayNode->items as $i => $item) {
            $output .= $printContext->newline
                . $printContext->childIndentation()
                . $this->printNode($item->value, $printContext->next(), $detectScalarMutation);

            if ($i < count($arrayNode->items) - 1) {
                $output .= ',';
            }
        }

        return $output . $printContext->newline . $printContext->indentation() . ']';
    }

    private function printEmptyArray(ArrayNode $arrayNode): string
    {
        if ($arrayNode->beforeCloseBracket !== '') {
            return '[' . $arrayNode->beforeCloseBracket . ']';
        }

        return '[]';
    }

    private function printArrayItemPreserving(
        ArrayItemNode $arrayItemNode,
        PrintContext $printContext,
        ?string $beforeValue = null,
        ?string $afterValue = null,
        bool $detectScalarMutation = false,
    ): string {
        $beforeValue        ??= $arrayItemNode->beforeValue;
        $afterValue         ??= $arrayItemNode->afterValue;
        $detectScalarMutation = $detectScalarMutation || $this->isExplicitlyChanged($arrayItemNode);

        if (
            $beforeValue === $arrayItemNode->beforeValue
            && $afterValue === $arrayItemNode->afterValue
            && ! $this->isChanged($arrayItemNode)
            && ! $detectScalarMutation
        ) {
            $originalText = $arrayItemNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (is_string($originalText)) {
                return $originalText;
            }
        }

        return $beforeValue
            . $this->printNode($arrayItemNode->value, $printContext, $detectScalarMutation)
            . $afterValue;
    }

    /**
     * @param list<ArrayItemNode|ObjectItemNode> $items
     */
    private function normalizeSyntheticAfterValue(
        array $items,
        int $index,
        string $afterValue,
        ArrayItemNode|ObjectItemNode $itemNode,
        string $containerBeforeClose,
    ): string {
        if (
            $afterValue === $containerBeforeClose
            && isset($items[$index + 1])
            && $this->isSyntheticItem($items[$index + 1])
        ) {
            return $this->separatorAfterValueBeforeSyntheticItem($items, $index, $containerBeforeClose);
        }

        if (
            ! $this->isSyntheticItem($itemNode)
            || $afterValue !== $containerBeforeClose
        ) {
            return $afterValue;
        }

        for ($i = $index + 1, $counter = count($items); $i < $counter; $i++) {
            if ($items[$i]->afterValue !== $containerBeforeClose) {
                return $items[$i]->afterValue;
            }
        }

        return '';
    }

    /**
     * @param list<ArrayItemNode|ObjectItemNode> $items
     */
    private function separatorAfterValueBeforeSyntheticItem(
        array $items,
        int $index,
        string $containerBeforeClose
    ): string {
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($items[$i]->afterValue !== $containerBeforeClose) {
                return $items[$i]->afterValue;
            }
        }

        return '';
    }

    private function isSyntheticItem(NodeJson $nodeJson): bool
    {
        return ! is_int($nodeJson->getAttribute(NodeAttributes::START_OFFSET))
            && ! is_string($nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT));
    }

    /**
     * @param list<NodeJson> $items
     */
    private function shouldPrintContainerBestEffort(NodeJson $nodeJson, array $items): bool
    {
        if ($this->nodeChangeSet instanceof NodeChangeSet && $this->nodeChangeSet->isChanged($nodeJson)) {
            if (
                ($nodeJson instanceof ObjectNode || $nodeJson instanceof ArrayNode)
                && $items !== []
                && $nodeJson->hasAttribute(NodeAttributes::ORIGINAL_TEXT)
                && ! $this->hasItemWithoutOriginalText($items)
            ) {
                return false;
            }

            return true;
        }

        return $this->hasItemWithoutOriginalText($items)
            || ! $nodeJson->hasAttribute(NodeAttributes::ORIGINAL_TEXT);
    }

    /**
     * @param list<NodeJson> $items
     */
    private function hasItemWithoutOriginalText(array $items): bool
    {
        foreach ($items as $item) {
            if (! $item->hasAttribute(NodeAttributes::ORIGINAL_TEXT)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T of NodeJson
     * @param list<T> $items
     * @return list<T>
     */
    private function getItemsInOriginalOrder(array $items): array
    {
        /** @var list<array{item: T, startOffset: float, currentIndex: int}> $itemsWithStartOffsets */
        $itemsWithStartOffsets = [];

        foreach ($items as $i => $item) {
            $startOffset = $item->getAttribute(NodeAttributes::START_OFFSET);

            if (! is_int($startOffset) && ! is_float($startOffset)) {
                $startOffset = $this->getSyntheticStartOffset($items, $i);
            }

            $itemsWithStartOffsets[] = [
                'item'         => $item,
                'startOffset'  => (float) $startOffset,
                'currentIndex' => $i,
            ];
        }

        usort(
            $itemsWithStartOffsets,
            /**
             * @param array{item: T, startOffset: float, currentIndex: int} $left
             * @param array{item: T, startOffset: float, currentIndex: int} $right
             */
            static fn (array $left, array $right): int => $left['startOffset'] <=> $right['startOffset']
                ?: $left['currentIndex'] <=> $right['currentIndex'],
        );

        /** @var list<T> $itemsInOriginalOrder */
        $itemsInOriginalOrder = [];

        foreach ($itemsWithStartOffsets as $itemWithStartOffset) {
            $itemsInOriginalOrder[] = $itemWithStartOffset['item'];
        }

        return $itemsInOriginalOrder;
    }

    /**
     * @param list<NodeJson> $items
     */
    private function getSyntheticStartOffset(array $items, int $index): float
    {
        $previousOffset = null;
        $previousIndex  = null;

        for ($i = $index - 1; $i >= 0; $i--) {
            $startOffset = $items[$i]->getAttribute(NodeAttributes::START_OFFSET);

            if (! is_int($startOffset) && ! is_float($startOffset)) {
                continue;
            }

            $previousOffset = $startOffset;
            $previousIndex  = $i;

            break;
        }

        $nextOffset = null;
        $nextIndex  = null;
        $counter    = count($items);

        for ($i = $index + 1; $i < $counter; $i++) {
            $startOffset = $items[$i]->getAttribute(NodeAttributes::START_OFFSET);

            if (! is_int($startOffset) && ! is_float($startOffset)) {
                continue;
            }

            $nextOffset = $startOffset;
            $nextIndex  = $i;

            break;
        }

        if ($previousOffset !== null && $previousIndex !== null && $nextOffset !== null && $nextIndex !== null) {
            $baseOffset = $previousOffset < $nextOffset ? $previousOffset : $nextOffset;
            $runLength  = $nextIndex - $previousIndex - 1;
            $position   = $index - $previousIndex;

            return $baseOffset + ($position / ($runLength + 1));
        }

        if ($previousOffset !== null && $previousIndex !== null) {
            $runLength = count($items) - $previousIndex - 1;
            $position  = $index - $previousIndex;

            return $previousOffset + ($position / ($runLength + 1));
        }

        if ($nextOffset !== null && $nextIndex !== null) {
            $runLength = $nextIndex;
            $position  = $nextIndex - $index;

            return $nextOffset - ($position / ($runLength + 1));
        }

        return (float) $index;
    }

    private function isChanged(NodeJson $nodeJson): bool
    {
        if ($this->nodeChangeSet instanceof NodeChangeSet && $this->nodeChangeSet->isChanged($nodeJson)) {
            return true;
        }

        if (! $nodeJson->hasAttribute(NodeAttributes::ORIGINAL_TEXT)) {
            return true;
        }

        $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        return $this->hasScalarValueChanged($nodeJson)
            || $this->hasStaleOriginalText($nodeJson)
            || $this->hasChangedDescendant($nodeJson)
            || ! is_string($originalText);
    }

    private function hasStaleOriginalText(NodeJson $nodeJson): bool
    {
        $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        if (! is_string($originalText)) {
            return false;
        }

        $reconstructedOriginalText = match (true) {
            $nodeJson instanceof JsonDocument => $nodeJson->beforeValue
                . $this->getOriginalText($nodeJson->value)
                . $nodeJson->afterValue,
            $nodeJson instanceof ObjectNode => $this->reconstructOriginalObjectText($nodeJson),
            $nodeJson instanceof ObjectItemNode => $nodeJson->beforeKey
                . $this->getOriginalText($nodeJson->key)
                . $nodeJson->betweenKeyAndColon
                . ':'
                . $nodeJson->betweenColonAndValue
                . $this->getOriginalText($nodeJson->value)
                . $nodeJson->afterValue,
            $nodeJson instanceof ArrayNode => $this->reconstructOriginalArrayText($nodeJson),
            $nodeJson instanceof ArrayItemNode => $nodeJson->beforeValue
                . $this->getOriginalText($nodeJson->value)
                . $nodeJson->afterValue,
            default => null,
        };

        return is_string($reconstructedOriginalText) && $reconstructedOriginalText !== $originalText;
    }

    private function getOriginalText(NodeJson $nodeJson): string
    {
        $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        return is_string($originalText) ? $originalText : '';
    }

    private function reconstructOriginalObjectText(ObjectNode $objectNode): string
    {
        if ($objectNode->items === []) {
            return '{' . $objectNode->beforeCloseBrace . '}';
        }

        $output    = '{';
        $lastIndex = count($objectNode->items) - 1;

        foreach ($objectNode->items as $i => $item) {
            $beforeKey  = $i === 0 ? $objectNode->afterOpenBrace : $item->beforeKey;
            $afterValue = $i === $lastIndex ? $objectNode->beforeCloseBrace : $item->afterValue;

            $output .= $beforeKey
                . $this->getOriginalText($item->key)
                . $item->betweenKeyAndColon
                . ':'
                . $item->betweenColonAndValue
                . $this->getOriginalText($item->value)
                . $afterValue;

            if ($i < $lastIndex) {
                $output .= ',';
            }
        }

        return $output . '}';
    }

    private function reconstructOriginalArrayText(ArrayNode $arrayNode): string
    {
        if ($arrayNode->items === []) {
            return '[' . $arrayNode->beforeCloseBracket . ']';
        }

        $output    = '[';
        $lastIndex = count($arrayNode->items) - 1;

        foreach ($arrayNode->items as $i => $item) {
            $beforeValue = $i === 0 ? $arrayNode->afterOpenBracket : $item->beforeValue;
            $afterValue  = $i === $lastIndex ? $arrayNode->beforeCloseBracket : $item->afterValue;

            $output .= $beforeValue
                . $this->getOriginalText($item->value)
                . $afterValue;

            if ($i < $lastIndex) {
                $output .= ',';
            }
        }

        return $output . ']';
    }

    private function isExplicitlyChanged(NodeJson $nodeJson): bool
    {
        if ($this->nodeChangeSet instanceof NodeChangeSet && $this->nodeChangeSet->isChanged($nodeJson)) {
            return true;
        }

        return ! $nodeJson->hasAttribute(NodeAttributes::ORIGINAL_TEXT);
    }

    private function hasScalarValueChanged(NodeJson $nodeJson): bool
    {
        if ($nodeJson instanceof StringNode) {
            return $this->hasStringValueChanged($nodeJson);
        }

        if ($nodeJson instanceof NumberNode) {
            return $this->hasNumberValueChanged($nodeJson);
        }

        if ($nodeJson instanceof BooleanNode) {
            return $this->hasBooleanValueChanged($nodeJson);
        }

        if ($nodeJson instanceof ObjectNode) {
            foreach ($nodeJson->items as $item) {
                if ($this->hasScalarValueChanged($item)) {
                    return true;
                }
            }

            return false;
        }

        if ($nodeJson instanceof ObjectItemNode) {
            return $this->hasScalarValueChanged($nodeJson->key)
                || $this->hasScalarValueChanged($nodeJson->value);
        }

        if ($nodeJson instanceof ArrayNode) {
            foreach ($nodeJson->items as $item) {
                if ($this->hasScalarValueChanged($item)) {
                    return true;
                }
            }

            return false;
        }

        if ($nodeJson instanceof ArrayItemNode) {
            return $this->hasScalarValueChanged($nodeJson->value);
        }

        return false;
    }

    private function hasStringValueChanged(StringNode $stringNode): bool
    {
        $originalText = $stringNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);
        $value        = is_string($originalText) ? json_decode($originalText, true) : null;

        return is_string($value) && $value !== $stringNode->value;
    }

    private function hasNumberValueChanged(NumberNode $numberNode): bool
    {
        $originalText = $numberNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        return is_string($originalText) && $originalText !== $numberNode->rawValue;
    }

    private function hasBooleanValueChanged(BooleanNode $booleanNode): bool
    {
        $originalText = $booleanNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        return is_string($originalText)
            && ($booleanNode->value ? 'true' : 'false') !== $originalText;
    }

    private function hasChangedDescendant(NodeJson $nodeJson): bool
    {
        if ($nodeJson instanceof JsonDocument) {
            return $this->isChanged($nodeJson->value);
        }

        if ($nodeJson instanceof ObjectNode) {
            foreach ($nodeJson->items as $item) {
                if ($this->isChanged($item)) {
                    return true;
                }
            }

            return false;
        }

        if ($nodeJson instanceof ObjectItemNode) {
            return $this->isChanged($nodeJson->key) || $this->isChanged($nodeJson->value);
        }

        if ($nodeJson instanceof ArrayNode) {
            foreach ($nodeJson->items as $item) {
                if ($this->isChanged($item)) {
                    return true;
                }
            }

            return false;
        }

        if ($nodeJson instanceof ArrayItemNode) {
            return $this->isChanged($nodeJson->value);
        }

        return false;
    }

    private function encodeString(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode JSON string.');
        }

        return $encoded;
    }
}
