<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Guard\MaximumDepthGuard;
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

use function array_pop;
use function count;
use function intdiv;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function strlen;
use function substr;
use function trim;
use function usort;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class JsonPreservingPrinter implements JsonPrinter
{
    public function __construct(
        private ?NodeChangeSet $nodeChangeSet = null,
        private ?string $indent = null,
        private int $maximumDepth = MaximumDepthGuard::DEFAULT_MAXIMUM_DEPTH,
    ) {
        MaximumDepthGuard::validateMaximumDepth($maximumDepth);
    }

    public function print(NodeJson $nodeJson): string
    {
        $this->guardNodeTreeMaximumDepth($nodeJson);

        $newline    = $nodeJson instanceof JsonDocument && is_string($nodeJson->getAttribute(NodeAttributes::NEWLINE))
            ? $nodeJson->getAttribute(NodeAttributes::NEWLINE)
            : "\n";
        $nodeIndent = $nodeJson->getAttribute(NodeAttributes::INDENT);
        $indent     = $this->indent
            ?? (is_string($nodeIndent) ? $nodeIndent : '    ');

        return $this->printNode($nodeJson, new PrintContext($indent, $newline), depth: 0);
    }

    private function printNode(
        NodeJson $nodeJson,
        PrintContext $printContext,
        bool $detectScalarMutation = false,
        int $depth = 0,
    ): string {
        if (
            ! $nodeJson instanceof JsonDocument
            && ! $nodeJson instanceof ObjectItemNode
            && ! $nodeJson instanceof ArrayItemNode
        ) {
            MaximumDepthGuard::guardMaximumDepth($this->maximumDepth, $depth);
        }

        $detectScalarMutation = $detectScalarMutation || $this->isExplicitlyChanged($nodeJson);

        if (! $this->isChanged($nodeJson)) {
            $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (
                is_string($originalText)
                && (! $detectScalarMutation || ! $this->hasScalarValueChanged($nodeJson))
            ) {
                return $nodeJson instanceof JsonDocument
                    ? $originalText
                    : $this->reindentOriginalText($nodeJson, $originalText, $printContext);
            }
        }

        return match (true) {
            $nodeJson instanceof JsonDocument => $this->printDocument(
                $nodeJson,
                $printContext,
                $detectScalarMutation,
                $depth,
            ),
            $nodeJson instanceof ObjectNode => $this->printContainer(
                $nodeJson,
                $printContext,
                $detectScalarMutation,
                $depth,
            ),
            $nodeJson instanceof ObjectItemNode => $this->printObjectItemPreserving(
                $nodeJson,
                $printContext,
                detectScalarMutation: $detectScalarMutation,
                depth: $depth,
            ),
            $nodeJson instanceof ArrayNode => $this->printContainer(
                $nodeJson,
                $printContext,
                $detectScalarMutation,
                $depth,
            ),
            $nodeJson instanceof ArrayItemNode => $this->printArrayItemPreserving(
                $nodeJson,
                $printContext,
                detectScalarMutation: $detectScalarMutation,
                depth: $depth,
            ),
            $nodeJson instanceof StringNode => $this->encodeString($nodeJson->value),
            $nodeJson instanceof NumberNode => $nodeJson->rawValue,
            $nodeJson instanceof BooleanNode => $nodeJson->value ? 'true' : 'false',
            $nodeJson instanceof NullNode => 'null',
            default => throw new RuntimeException('Unsupported JSON node.'),
        };
    }

    private function guardNodeTreeMaximumDepth(NodeJson $nodeJson): void
    {
        /** @var list<array{NodeJson, int}> $stack */
        $stack = [[$nodeJson, 0]];

        while ($stack !== []) {
            /** @var array{NodeJson, int} $entry */
            $entry = array_pop($stack);

            [$currentNode, $depth] = $entry;

            if (
                ! $currentNode instanceof JsonDocument
                && ! $currentNode instanceof ObjectItemNode
                && ! $currentNode instanceof ArrayItemNode
            ) {
                MaximumDepthGuard::guardMaximumDepth($this->maximumDepth, $depth);
            }

            if ($currentNode instanceof JsonDocument) {
                $stack[] = [$currentNode->value, $depth];
                continue;
            }

            if ($currentNode instanceof ObjectNode) {
                foreach ($currentNode->items as $item) {
                    $stack[] = [$item, $depth + 1];
                }

                continue;
            }

            if ($currentNode instanceof ObjectItemNode) {
                $stack[] = [$currentNode->key, $depth];
                $stack[] = [$currentNode->value, $depth];
                continue;
            }

            if ($currentNode instanceof ArrayNode) {
                foreach ($currentNode->items as $item) {
                    $stack[] = [$item, $depth + 1];
                }

                continue;
            }

            if ($currentNode instanceof ArrayItemNode) {
                $stack[] = [$currentNode->value, $depth];
            }
        }
    }

    private function printDocument(
        JsonDocument $jsonDocument,
        PrintContext $printContext,
        bool $detectScalarMutation,
        int $depth,
    ): string {
        $output = $jsonDocument->beforeValue
            . $this->printNode($jsonDocument->value, $printContext, $detectScalarMutation, $depth)
            . $jsonDocument->afterValue;

        if (
            $jsonDocument->getAttribute(NodeAttributes::TRAILING_NEWLINE) === true
            && ! str_ends_with($output, $printContext->newline)
        ) {
            $output .= $printContext->newline;
        }

        return $output;
    }

    private function printContainer(
        ArrayNode|ObjectNode $containerNode,
        PrintContext $printContext,
        bool $detectScalarMutation,
        int $depth,
    ): string {
        $childDetectScalarMutation = $detectScalarMutation || $this->isExplicitlyChanged($containerNode);

        if (
            $this->shouldPrintContainerBestEffort($containerNode, $containerNode->items)
            || $this->shouldPrintInsertedMultilineItemsBestEffort($containerNode)
            || $this->shouldPrintChangedMultilineItemValuesBestEffort(
                $containerNode->items,
                $printContext,
                $childDetectScalarMutation,
                $depth,
            )
        ) {
            return $this->printContainerBestEffort($containerNode, $printContext, $detectScalarMutation, $depth);
        }

        if ($containerNode->items === []) {
            return $this->printEmptyContainer($containerNode);
        }

        $detectScalarMutation = $childDetectScalarMutation;
        $output               = $this->openingDelimiter($containerNode);
        $lastIndex            = count($containerNode->items) - 1;
        $itemsInOriginalOrder = $this->getItemsInOriginalOrder($containerNode->items);

        foreach ($containerNode->items as $i => $item) {
            [$beforeItem, $afterValue] = $this->getItemLayout(
                $containerNode->items,
                $i,
                $itemsInOriginalOrder,
                $this->afterOpen($containerNode),
                $this->beforeClose($containerNode),
            );

            $output .= $item instanceof ObjectItemNode
                ? $this->printObjectItemPreserving(
                    $item,
                    $printContext->next(),
                    $beforeItem,
                    $afterValue,
                    $detectScalarMutation,
                    $depth + 1,
                )
                : $this->printArrayItemPreserving(
                    $item,
                    $printContext->next(),
                    $beforeItem,
                    $afterValue,
                    $detectScalarMutation,
                    $depth + 1,
                );

            if ($i < $lastIndex) {
                $output .= ',';
            }
        }

        return $output . $this->closingDelimiter($containerNode);
    }

    private function printContainerBestEffort(
        ArrayNode|ObjectNode $containerNode,
        PrintContext $printContext,
        bool $detectScalarMutation,
        int $depth,
    ): string {
        if ($containerNode->items === []) {
            return $this->printEmptyContainer($containerNode);
        }

        $detectScalarMutation = $detectScalarMutation || $this->isExplicitlyChanged($containerNode);
        $output               = $this->openingDelimiter($containerNode);

        foreach ($containerNode->items as $i => $item) {
            $output .= $printContext->newline
                . $printContext->childIndentation()
                . ($item instanceof ObjectItemNode
                    ? $this->printObjectItemBestEffort($item, $printContext->next(), $detectScalarMutation, $depth + 1)
                    : $this->printNode($item->value, $printContext->next(), $detectScalarMutation, $depth + 1));

            if ($i < count($containerNode->items) - 1) {
                $output .= ',';
            }
        }

        return $output
            . $printContext->newline
            . $printContext->indentation()
            . $this->closingDelimiter($containerNode);
    }

    private function printEmptyContainer(ArrayNode|ObjectNode $containerNode): string
    {
        $beforeClose = $this->beforeClose($containerNode);

        if ($beforeClose !== '') {
            return $this->openingDelimiter($containerNode) . $beforeClose . $this->closingDelimiter($containerNode);
        }

        return $this->openingDelimiter($containerNode) . $this->closingDelimiter($containerNode);
    }

    private function openingDelimiter(ArrayNode|ObjectNode $containerNode): string
    {
        return $containerNode instanceof ArrayNode ? '[' : '{';
    }

    private function closingDelimiter(ArrayNode|ObjectNode $containerNode): string
    {
        return $containerNode instanceof ArrayNode ? ']' : '}';
    }

    private function afterOpen(ArrayNode|ObjectNode $containerNode): string
    {
        return $containerNode instanceof ArrayNode
            ? $containerNode->afterOpenBracket
            : $containerNode->afterOpenBrace;
    }

    private function beforeClose(ArrayNode|ObjectNode $containerNode): string
    {
        return $containerNode instanceof ArrayNode
            ? $containerNode->beforeCloseBracket
            : $containerNode->beforeCloseBrace;
    }

    private function printObjectItemPreserving(
        ObjectItemNode $objectItemNode,
        PrintContext $printContext,
        ?string $beforeKey = null,
        ?string $afterValue = null,
        bool $detectScalarMutation = false,
        int $depth = 0,
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
                return $this->reindentOriginalText($objectItemNode, $originalText, $printContext);
            }
        }

        return $beforeKey
            . $this->printNode($objectItemNode->key, $printContext, $detectScalarMutation, $depth)
            . $objectItemNode->betweenKeyAndColon
            . ':'
            . $objectItemNode->betweenColonAndValue
            . $this->printNode($objectItemNode->value, $printContext, $detectScalarMutation, $depth)
            . $afterValue;
    }

    private function printObjectItemBestEffort(
        ObjectItemNode $objectItemNode,
        PrintContext $printContext,
        bool $detectScalarMutation,
        int $depth,
    ): string {
        return $this->printNode($objectItemNode->key, $printContext, $detectScalarMutation, $depth)
            . ': '
            . $this->printNode($objectItemNode->value, $printContext, $detectScalarMutation, $depth);
    }

    private function printArrayItemPreserving(
        ArrayItemNode $arrayItemNode,
        PrintContext $printContext,
        ?string $beforeValue = null,
        ?string $afterValue = null,
        bool $detectScalarMutation = false,
        int $depth = 0,
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
                return $this->reindentOriginalText($arrayItemNode, $originalText, $printContext);
            }
        }

        return $beforeValue
            . $this->printNode($arrayItemNode->value, $printContext, $detectScalarMutation, $depth)
            . $afterValue;
    }

    /**
     * @param list<ArrayItemNode|ObjectItemNode> $items
     * @param list<ArrayItemNode|ObjectItemNode> $itemsInOriginalOrder
     * @return array{?string, ?string}
     */
    private function getItemLayout(
        array $items,
        int $index,
        array $itemsInOriginalOrder,
        string $containerAfterOpen,
        string $containerBeforeClose,
    ): array {
        $item        = $items[$index];
        $lastIndex   = count($items) - 1;
        $layoutDonor = $itemsInOriginalOrder === $items ? $item : $itemsInOriginalOrder[$index];
        $beforeValue = $index === 0 ? $containerAfterOpen : null;
        $afterValue  = $index === $lastIndex ? $containerBeforeClose : $layoutDonor->afterValue;

        if ($index > 0 && $layoutDonor !== $item) {
            $beforeValue = $layoutDonor instanceof ObjectItemNode
                ? $layoutDonor->beforeKey
                : $layoutDonor->beforeValue;
        }

        if ($index < $lastIndex) {
            $afterValue = $this->normalizeSyntheticAfterValue(
                $items,
                $index,
                $afterValue,
                $layoutDonor,
                $containerBeforeClose,
            );
        }

        return [$beforeValue, $afterValue];
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
            return $this->findSeparatorBeforeIndex($items, $index, $containerBeforeClose);
        }

        if (! $this->isSyntheticItem($itemNode) || $afterValue !== $containerBeforeClose) {
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
    private function findSeparatorBeforeIndex(
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

    private function shouldPrintInsertedMultilineItemsBestEffort(ArrayNode|ObjectNode $containerNode): bool
    {
        if ($this->hasContainerEdgeWhitespace($containerNode)) {
            return false;
        }

        foreach ($containerNode->items as $item) {
            if (! $this->isSyntheticItem($item)) {
                continue;
            }

            $originalText = $item->value->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (
                is_string($originalText)
                && (str_contains($originalText, "\n") || str_contains($originalText, "\r"))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ArrayItemNode|ObjectItemNode> $items
     */
    private function shouldPrintChangedMultilineItemValuesBestEffort(
        array $items,
        PrintContext $printContext,
        bool $detectScalarMutation,
        int $depth,
    ): bool {
        foreach ($items as $item) {
            if (! $this->isChanged($item->value)) {
                continue;
            }

            $printedValue = $this->printNode($item->value, $printContext->next(), $detectScalarMutation, $depth + 1);

            if (str_contains($printedValue, "\n") || str_contains($printedValue, "\r")) {
                return true;
            }
        }

        return false;
    }

    private function hasContainerEdgeWhitespace(ArrayNode|ObjectNode $containerNode): bool
    {
        if ($containerNode instanceof ArrayNode) {
            return $containerNode->afterOpenBracket !== '' || $containerNode->beforeCloseBracket !== '';
        }

        return $containerNode->afterOpenBrace !== '' || $containerNode->beforeCloseBrace !== '';
    }

    private function reindentOriginalText(
        NodeJson $nodeJson,
        string $originalText,
        PrintContext $printContext,
    ): string {
        $originalDepth = $nodeJson->getAttribute(NodeAttributes::DEPTH);

        if (! is_int($originalDepth)) {
            return $originalText;
        }

        $delta          = $printContext->level() - $originalDepth;
        $originalIndent = $nodeJson->getAttribute(NodeAttributes::INDENT);

        if (
            is_string($originalIndent)
            && $originalIndent !== ''
            && $originalIndent !== $printContext->indentUnit()
        ) {
            /** @var list<string> $lines */
            $lines  = preg_split('/(?<=\r\n|\r|\n)/', $originalText);
            $output = $lines[0];

            for ($i = 1, $count = count($lines); $i < $count; $i++) {
                $line = $lines[$i];

                if (trim($line) === '') {
                    $output .= $line;

                    continue;
                }

                $leadingWhitespaceLength = 0;

                while (
                    isset($line[$leadingWhitespaceLength])
                    && ($line[$leadingWhitespaceLength] === ' ' || $line[$leadingWhitespaceLength] === "\t")
                ) {
                    $leadingWhitespaceLength++;
                }

                $originalIndentLength = strlen($originalIndent);
                $indentLevel          = intdiv(
                    $leadingWhitespaceLength + intdiv($originalIndentLength, 2),
                    $originalIndentLength,
                );
                $residual             = $leadingWhitespaceLength - ($indentLevel * $originalIndentLength);

                $targetLevel  = $indentLevel + $delta;
                $targetPrefix = str_repeat(
                    $printContext->indentUnit(),
                    max($targetLevel, 0),
                );

                if ($residual < 0) {
                    $targetPrefix = substr($targetPrefix, 0, $residual);
                } elseif ($residual > 0) {
                    $targetPrefix .= substr(
                        $line,
                        $leadingWhitespaceLength - $residual,
                        $residual,
                    );
                }

                $output .= $targetPrefix . substr($line, $leadingWhitespaceLength);
            }

            return $output;
        }

        if ($delta === 0 || $printContext->indentUnit() === '') {
            return $originalText;
        }

        /** @var list<string> $lines */
        $lines = preg_split('/(?<=\r\n|\r|\n)/', $originalText);

        $output       = $lines[0];
        $addPrefix    = $delta > 0 ? str_repeat($printContext->indentUnit(), $delta) : '';
        $removeLength = $delta < 0 ? strlen($printContext->indentUnit()) * -$delta : 0;

        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $output .= $line;

                continue;
            }

            if ($delta > 0) {
                $output .= $addPrefix . $line;

                continue;
            }

            $stripLength = 0;
            while (
                $stripLength < $removeLength
                && isset($line[$stripLength])
                && ($line[$stripLength] === ' ' || $line[$stripLength] === "\t")
            ) {
                $stripLength++;
            }

            $output .= substr($line, $stripLength);
        }

        return $output;
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
