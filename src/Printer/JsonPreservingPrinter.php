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

use function abs;
use function array_pop;
use function count;
use function intdiv;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function strlen;
use function strrpos;
use function strspn;
use function substr;
use function substr_compare;
use function trim;
use function usort;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class JsonPreservingPrinter implements JsonPrinter
{
    /** @var positive-int */
    private int $maximumDepth;

    public function __construct(
        private ?NodeChangeSet $nodeChangeSet = null,
        private ?string $indent = null,
        int $maximumDepth = MaximumDepthGuard::DEFAULT_MAXIMUM_DEPTH,
    ) {
        $this->maximumDepth = MaximumDepthGuard::validateMaximumDepth($maximumDepth);
    }

    public function print(NodeJson $nodeJson): string
    {
        $this->guardNodeTreeMaximumDepth($nodeJson);

        $nodeNewline = $nodeJson->getAttribute(NodeAttributes::NEWLINE);
        $newline     = is_string($nodeNewline) ? $nodeNewline : "\n";
        $nodeIndent  = $nodeJson->getAttribute(NodeAttributes::INDENT);
        $indent      = $this->indent
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
            $nodeJson instanceof ObjectNode, $nodeJson instanceof ArrayNode => $this->printContainer(
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

            if ($currentNode instanceof ObjectItemNode) {
                $stack[] = [$currentNode->key, $depth];
                $stack[] = [$currentNode->value, $depth];
                continue;
            }

            if ($currentNode instanceof ObjectNode || $currentNode instanceof ArrayNode) {
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
            && ! str_ends_with($output, "\n")
            && ! str_ends_with($output, "\r")
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
        $printedChangedItemValues  = [];
        $shouldPrintBestEffort     = $this->shouldPrintContainerBestEffort($containerNode, $containerNode->items)
            || $this->shouldPrintInsertedMultilineItemsBestEffort($containerNode);

        if (! $shouldPrintBestEffort) {
            [$shouldPrintBestEffort, $printedChangedItemValues] = $this->printChangedItemValues(
                $containerNode,
                $printContext,
                $childDetectScalarMutation,
                $depth,
            );
        }

        if ($shouldPrintBestEffort) {
            return $this->printContainerBestEffort(
                $containerNode,
                $printContext,
                $detectScalarMutation,
                $depth,
                $printedChangedItemValues,
            );
        }

        if ($containerNode->items === []) {
            return $this->printEmptyContainer($containerNode, $printContext);
        }

        $detectScalarMutation = $childDetectScalarMutation;
        $output               = $this->openingDelimiter($containerNode);
        $lastIndex            = count($containerNode->items) - 1;
        $itemsInOriginalOrder = $this->getItemsInOriginalOrder($containerNode->items);
        $interiorShift        = $this->resolveInteriorItemShift($containerNode, $printContext);

        foreach ($containerNode->items as $i => $item) {
            [$beforeItem, $afterValue] = $this->getItemLayout(
                $containerNode->items,
                $i,
                $itemsInOriginalOrder,
                $this->afterOpen($containerNode),
                $this->reindentWhitespaceBeforeNode(
                    $containerNode,
                    $this->beforeClose($containerNode),
                    $printContext,
                ),
            );

            $beforeItem ??= $this->beforeItem($item);
            $beforeItem   = $interiorShift !== null
                ? $this->shiftWhitespaceBeforeNode($beforeItem, $interiorShift)
                : $this->reindentWhitespaceBeforeNode($item, $beforeItem, $printContext->next());

            $output .= $item instanceof ObjectItemNode
                ? $this->printObjectItemPreserving(
                    $item,
                    $printContext->next(),
                    $beforeItem,
                    $afterValue,
                    $detectScalarMutation,
                    $depth + 1,
                    $printedChangedItemValues[$i] ?? null,
                )
                : $this->printArrayItemPreserving(
                    $item,
                    $printContext->next(),
                    $beforeItem,
                    $afterValue,
                    $detectScalarMutation,
                    $depth + 1,
                    $printedChangedItemValues[$i] ?? null,
                );

            if ($i < $lastIndex) {
                $output .= ',';
            }
        }

        return $output . $this->closingDelimiter($containerNode);
    }

    /**
     * @param array<int, string> $printedChangedItemValues
     */
    private function printContainerBestEffort(
        ArrayNode|ObjectNode $containerNode,
        PrintContext $printContext,
        bool $detectScalarMutation,
        int $depth,
        array $printedChangedItemValues = [],
    ): string {
        if ($containerNode->items === []) {
            return $this->printEmptyContainer($containerNode, $printContext);
        }

        $detectScalarMutation = $detectScalarMutation || $this->isExplicitlyChanged($containerNode);
        $output               = $this->openingDelimiter($containerNode);

        foreach ($containerNode->items as $i => $item) {
            $output .= $printContext->newline
                . $printContext->childIndentation()
                . ($item instanceof ObjectItemNode
                    ? $this->printObjectItemBestEffort(
                        $item,
                        $printContext->next(),
                        $detectScalarMutation,
                        $depth + 1,
                        $printedChangedItemValues[$i] ?? null,
                    )
                    : ($printedChangedItemValues[$i]
                        ?? $this->printNode(
                            $item->value,
                            $printContext->next(),
                            $detectScalarMutation,
                            $depth + 1,
                        )));

            if ($i < count($containerNode->items) - 1) {
                $output .= ',';
            }
        }

        return $output
            . $printContext->newline
            . $printContext->indentation()
            . $this->closingDelimiter($containerNode);
    }

    private function printEmptyContainer(ArrayNode|ObjectNode $containerNode, PrintContext $printContext): string
    {
        $beforeClose = $this->reindentWhitespaceBeforeNode(
            $containerNode,
            $this->beforeClose($containerNode),
            $printContext,
        );

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

    private function beforeItem(ArrayItemNode|ObjectItemNode $item): string
    {
        return $item instanceof ObjectItemNode ? $item->beforeKey : $item->beforeValue;
    }

    private function printObjectItemPreserving(
        ObjectItemNode $objectItemNode,
        PrintContext $printContext,
        ?string $beforeKey = null,
        ?string $afterValue = null,
        bool $detectScalarMutation = false,
        int $depth = 0,
        ?string $printedValue = null,
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
            . $this->objectItemSeparator($objectItemNode)
            . ($printedValue
                ?? $this->printNode($objectItemNode->value, $printContext, $detectScalarMutation, $depth))
            . $afterValue;
    }

    private function printObjectItemBestEffort(
        ObjectItemNode $objectItemNode,
        PrintContext $printContext,
        bool $detectScalarMutation,
        int $depth,
        ?string $printedValue = null,
    ): string {
        return $this->printNode($objectItemNode->key, $printContext, $detectScalarMutation, $depth)
            . $this->objectItemSeparator($objectItemNode)
            . ($printedValue
                ?? $this->printNode($objectItemNode->value, $printContext, $detectScalarMutation, $depth));
    }

    private function objectItemSeparator(ObjectItemNode $objectItemNode): string
    {
        $originalText = $objectItemNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        if (
            is_string($originalText)
            || $objectItemNode->hasAttribute(NodeAttributes::START_OFFSET)
            || $objectItemNode->betweenKeyAndColon !== ''
            || $objectItemNode->betweenColonAndValue !== ''
        ) {
            return $objectItemNode->betweenKeyAndColon . ':' . $objectItemNode->betweenColonAndValue;
        }

        return ': ';
    }

    private function printArrayItemPreserving(
        ArrayItemNode $arrayItemNode,
        PrintContext $printContext,
        ?string $beforeValue = null,
        ?string $afterValue = null,
        bool $detectScalarMutation = false,
        int $depth = 0,
        ?string $printedValue = null,
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
            . ($printedValue
                ?? $this->printNode($arrayItemNode->value, $printContext, $detectScalarMutation, $depth))
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
        if ($this->hasContainerMultilineEdgeWhitespace($containerNode)) {
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

    /** @return array{bool, array<int, string>} */
    private function printChangedItemValues(
        ArrayNode|ObjectNode $containerNode,
        PrintContext $printContext,
        bool $detectScalarMutation,
        int $depth,
    ): array {
        $printedValues = [];

        foreach ($containerNode->items as $i => $item) {
            if (! $this->isChanged($item) && ! $this->isChanged($item->value)) {
                continue;
            }

            $printedValues[$i] = $this->printNode(
                $item->value,
                $printContext->next(),
                $detectScalarMutation,
                $depth + 1,
            );

            if (str_contains($printedValues[$i], "\n") || str_contains($printedValues[$i], "\r")) {
                return [! $this->hasContainerMultilineEdgeWhitespace($containerNode), $printedValues];
            }
        }

        return [false, $printedValues];
    }

    private function hasContainerMultilineEdgeWhitespace(ArrayNode|ObjectNode $containerNode): bool
    {
        $afterOpen   = $this->afterOpen($containerNode);
        $beforeClose = $this->beforeClose($containerNode);

        return str_contains($afterOpen, "\n")
            || str_contains($afterOpen, "\r")
            || str_contains($beforeClose, "\n")
            || str_contains($beforeClose, "\r");
    }

    private function reindentWhitespaceBeforeNode(
        NodeJson $nodeJson,
        string $whitespace,
        PrintContext $printContext,
    ): string {
        $lastNewlinePosition = $this->lastNewlinePosition($whitespace);

        if ($lastNewlinePosition < 0) {
            return $whitespace;
        }

        return substr($whitespace, 0, $lastNewlinePosition + 1)
            . $this->reindentLeadingWhitespace(
                $nodeJson,
                substr($whitespace, $lastNewlinePosition + 1),
                $printContext,
            );
    }

    private function shiftWhitespaceBeforeNode(string $whitespace, int $interiorShift): string
    {
        $lastNewlinePosition = $this->lastNewlinePosition($whitespace);

        if ($lastNewlinePosition < 0) {
            return $whitespace;
        }

        return substr($whitespace, 0, $lastNewlinePosition + 1)
            . substr($whitespace, $lastNewlinePosition + 1 - $interiorShift);
    }

    private function lastNewlinePosition(string $whitespace): int
    {
        $lineFeedPosition       = strrpos($whitespace, "\n");
        $carriageReturnPosition = strrpos($whitespace, "\r");

        return max(
            $lineFeedPosition === false ? -1 : $lineFeedPosition,
            $carriageReturnPosition === false ? -1 : $carriageReturnPosition,
        );
    }

    private function resolveInteriorItemShift(
        ArrayNode|ObjectNode $containerNode,
        PrintContext $printContext,
    ): ?int {
        $originalDepth = $containerNode->getAttribute(NodeAttributes::DEPTH);

        if (! is_int($originalDepth)) {
            return null;
        }

        $itemWhitespace = [$this->afterOpen($containerNode)];
        foreach ($containerNode->items as $item) {
            $itemWhitespace[] = $this->beforeItem($item);
        }

        $itemLeads = [];
        foreach ($itemWhitespace as $whitespace) {
            $lastNewlinePosition = $this->lastNewlinePosition($whitespace);

            if ($lastNewlinePosition < 0) {
                continue;
            }

            $itemLeads[] = substr($whitespace, $lastNewlinePosition + 1);
        }

        return $this->resolveOffGridInteriorShift(
            $containerNode->getAttribute(NodeAttributes::INDENT),
            $itemLeads,
            $printContext->indentUnit(),
            $printContext->level() - $originalDepth,
        );
    }

    private function reindentLeadingWhitespace(
        NodeJson $nodeJson,
        string $leadingWhitespace,
        PrintContext $printContext,
    ): string {
        $originalDepth = $nodeJson->getAttribute(NodeAttributes::DEPTH);

        if (! is_int($originalDepth)) {
            return $leadingWhitespace;
        }

        $delta          = $printContext->level() - $originalDepth;
        $originalIndent = $nodeJson->getAttribute(NodeAttributes::INDENT);

        if (
            is_string($originalIndent)
            && $originalIndent !== ''
            && $originalIndent !== $printContext->indentUnit()
        ) {
            return $this->reindentLeadingWhitespaceUnit(
                $leadingWhitespace,
                $originalIndent,
                $printContext->indentUnit(),
                $delta,
            );
        }

        if ($delta === 0 || $printContext->indentUnit() === '') {
            return $leadingWhitespace;
        }

        if ($delta > 0) {
            return str_repeat($printContext->indentUnit(), $delta) . $leadingWhitespace;
        }

        $removeLength = strlen($printContext->indentUnit()) * -$delta;
        $stripLength  = 0;

        while (
            $stripLength < $removeLength
            && isset($leadingWhitespace[$stripLength])
            && ($leadingWhitespace[$stripLength] === ' ' || $leadingWhitespace[$stripLength] === "\t")
        ) {
            $stripLength++;
        }

        return substr($leadingWhitespace, $stripLength);
    }

    private function reindentLeadingWhitespaceUnit(
        string $leadingWhitespace,
        string $originalIndent,
        string $targetIndent,
        int $delta,
    ): string {
        $leadingWhitespaceLength = strlen($leadingWhitespace);
        $originalIndentLength    = strlen($originalIndent);
        $targetIndentLength      = strlen($targetIndent);

        if (str_contains($originalIndent, "\t")) {
            $wholeIndentLevel = 0;
            $residualOffset   = 0;

            while (
                $residualOffset + $originalIndentLength <= $leadingWhitespaceLength
                && substr_compare($leadingWhitespace, $originalIndent, $residualOffset, $originalIndentLength) === 0
            ) {
                $wholeIndentLevel++;
                $residualOffset += $originalIndentLength;
            }

            $residualWhitespace = substr($leadingWhitespace, $residualOffset);

            // A pure-space residual after tab units is carried verbatim: it is the exact
            // remainder a space->tab conversion left behind, so byte-scaling it would
            // misread "\t  " as three tab levels instead of one level plus two spaces.
            if (strspn($residualWhitespace, ' ') === strlen($residualWhitespace)) {
                return str_repeat($targetIndent, max($wholeIndentLevel + $delta, 0))
                    . $residualWhitespace;
            }
        }

        $indentLevel = intdiv(
            $leadingWhitespaceLength + intdiv($originalIndentLength, 2),
            $originalIndentLength,
        );
        $residual    = $leadingWhitespaceLength - ($indentLevel * $originalIndentLength);

        if ($targetIndentLength === 0) {
            return $residual > 0
                ? substr($leadingWhitespace, $leadingWhitespaceLength - $residual, $residual)
                : '';
        }

        $targetLevel  = $indentLevel + $delta;
        $targetPrefix = str_repeat($targetIndent, max($targetLevel, 0));

        if (str_contains($targetIndent, "\t")) {
            $wholeIndentLevel       = intdiv($leadingWhitespaceLength, $originalIndentLength);
            $targetWholeIndentLevel = $wholeIndentLevel + $delta;

            if ($targetWholeIndentLevel < 0) {
                return '';
            }

            $residualOffset = $wholeIndentLevel * $originalIndentLength;

            return str_repeat($targetIndent, $targetWholeIndentLevel)
                . substr($leadingWhitespace, $residualOffset);
        }

        if ($targetLevel < 0 || ($targetLevel === 0 && $residual < 0)) {
            // Clamped lines plateau flush at the target container: lifting any of
            // them (or keeping a scaled residual below the boundary) would print a
            // shallower source line deeper than an aligned clamped sibling.
            return '';
        }

        $scaledResidual = intdiv(
            (abs($residual) * $targetIndentLength) + intdiv($originalIndentLength, 2),
            $originalIndentLength,
        );

        if ($scaledResidual === 0) {
            return $targetPrefix;
        }

        if ($residual < 0) {
            return substr($targetPrefix, 0, -$scaledResidual);
        }

        return $targetPrefix . str_repeat(' ', $scaledResidual);
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

        /** @var list<string> $lines */
        $lines = preg_split('/(?<=\r\n|\r|\n)/', $originalText);

        $output = $lines[0];
        $count  = count($lines);

        $interiorLeads = [];
        for ($i = 1; $i < $count - 1; $i++) {
            if (trim($lines[$i]) === '') {
                continue;
            }

            $interiorLeads[] = substr($lines[$i], 0, strspn($lines[$i], " \t"));
        }

        $interiorShift = $this->resolveOffGridInteriorShift(
            $nodeJson->getAttribute(NodeAttributes::INDENT),
            $interiorLeads,
            $printContext->indentUnit(),
            $printContext->level() - $originalDepth,
        );

        for ($i = 1; $i < $count; $i++) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $output .= $line;

                continue;
            }

            $leadingWhitespaceLength = strspn($line, " \t");
            $leadingWhitespace       = substr($line, 0, $leadingWhitespaceLength);

            // Interior lines off the original indent grid carry intentional relative
            // indentation that per-line level scaling would flatten; shift the whole
            // interior by the depth delta instead. The closing line still scales so
            // the bracket aligns with its container.
            $output .= ($interiorShift !== null && $i < $count - 1
                ? substr($leadingWhitespace, -$interiorShift)
                : $this->reindentLeadingWhitespace($nodeJson, $leadingWhitespace, $printContext))
                . substr($line, $leadingWhitespaceLength);
        }

        return $output;
    }

    /**
     * Byte shift applied to every interior lead of a container whose lines sit off
     * the original indent grid: the depth delta in target units, clamped so the
     * shallowest interior lead lands at the margin instead of being truncated —
     * relative indentation between the leads is preserved exactly.
     *
     * @param list<string> $interiorLeads
     */
    private function resolveOffGridInteriorShift(
        mixed $originalIndent,
        array $interiorLeads,
        string $targetIndent,
        int $delta,
    ): ?int {
        if (
            ! is_string($originalIndent)
            || $originalIndent === ''
            || $originalIndent === $targetIndent
            || $interiorLeads === []
            || ! $this->hasClampedLeadOffOriginalIndentGrid($interiorLeads, $originalIndent, $delta)
        ) {
            return null;
        }

        $minimumLeadLength = strlen($interiorLeads[0]);
        foreach ($interiorLeads as $interiorLead) {
            $minimumLeadLength = min($minimumLeadLength, strlen($interiorLead));
        }

        return max($delta * strlen($targetIndent), -$minimumLeadLength);
    }

    /**
     * @param list<string> $leads
     */
    private function hasClampedLeadOffOriginalIndentGrid(
        array $leads,
        string $originalIndent,
        int $delta,
    ): bool {
        if ($delta >= 0) {
            return false;
        }

        $originalIndentLength = strlen($originalIndent);

        foreach ($leads as $lead) {
            $leadLength = strlen($lead);

            $indentLevel = intdiv(
                $leadLength + intdiv($originalIndentLength, 2),
                $originalIndentLength,
            );

            if ($indentLevel + $delta > 0) {
                continue;
            }

            if ($leadLength % $originalIndentLength !== 0) {
                return true;
            }

            if ($lead !== str_repeat($originalIndent, intdiv($leadLength, $originalIndentLength))) {
                return true;
            }
        }

        return false;
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
            $nodeJson instanceof ObjectNode, $nodeJson instanceof ArrayNode => $this->reconstructOriginalContainerText(
                $nodeJson,
            ),
            $nodeJson instanceof ObjectItemNode => $nodeJson->beforeKey
                . $this->getOriginalText($nodeJson->key)
                . $nodeJson->betweenKeyAndColon
                . ':'
                . $nodeJson->betweenColonAndValue
                . $this->getOriginalText($nodeJson->value)
                . $nodeJson->afterValue,
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

    private function reconstructOriginalContainerText(ObjectNode|ArrayNode $containerNode): string
    {
        if ($containerNode->items === []) {
            return $this->openingDelimiter($containerNode)
                . $this->beforeClose($containerNode)
                . $this->closingDelimiter($containerNode);
        }

        $output    = $this->openingDelimiter($containerNode);
        $lastIndex = count($containerNode->items) - 1;

        foreach ($containerNode->items as $i => $item) {
            $beforeValue = $i === 0
                ? $this->afterOpen($containerNode)
                : $this->beforeItem($item);
            $afterValue  = $i === $lastIndex ? $this->beforeClose($containerNode) : $item->afterValue;

            $output .= $beforeValue
                . $this->reconstructOriginalContainerItemText($item)
                . $afterValue;

            if ($i < $lastIndex) {
                $output .= ',';
            }
        }

        return $output . $this->closingDelimiter($containerNode);
    }

    private function reconstructOriginalContainerItemText(ObjectItemNode|ArrayItemNode $item): string
    {
        return match (true) {
            $item instanceof ObjectItemNode => $this->getOriginalText($item->key)
                . $item->betweenKeyAndColon
                . ':'
                . $item->betweenColonAndValue
                . $this->getOriginalText($item->value),
            $item instanceof ArrayItemNode => $this->getOriginalText($item->value),
        };
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
        return match (true) {
            $nodeJson instanceof StringNode => $this->hasStringValueChanged($nodeJson),
            $nodeJson instanceof NumberNode => $this->hasNumberValueChanged($nodeJson),
            $nodeJson instanceof BooleanNode => $this->hasBooleanValueChanged($nodeJson),
            default => false,
        };
    }

    private function hasStringValueChanged(StringNode $stringNode): bool
    {
        $originalText = $stringNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);
        $value        = is_string($originalText)
            ? json_decode($originalText, true, $this->maximumDepth)
            : null;

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

        if ($nodeJson instanceof ObjectItemNode) {
            return $this->isChanged($nodeJson->key) || $this->isChanged($nodeJson->value);
        }

        if ($nodeJson instanceof ArrayItemNode) {
            return $this->isChanged($nodeJson->value);
        }

        if ($nodeJson instanceof ObjectNode || $nodeJson instanceof ArrayNode) {
            return $this->hasChangedContainerItem($nodeJson);
        }

        return false;
    }

    private function hasChangedContainerItem(ObjectNode|ArrayNode $containerNode): bool
    {
        foreach ($containerNode->items as $item) {
            if ($this->isChanged($item)) {
                return true;
            }
        }

        return false;
    }

    private function encodeString(string $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            $this->maximumDepth,
        );

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode JSON string.');
        }

        return $encoded;
    }
}
