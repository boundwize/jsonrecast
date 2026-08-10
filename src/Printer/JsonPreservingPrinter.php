<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Guard\IndentGuard;
use Boundwize\JsonRecast\Guard\MaximumDepthGuard;
use Boundwize\JsonRecast\Guard\NewlineGuard;
use Boundwize\JsonRecast\Guard\NodeTreeGuard;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\Helper\StartOffsetHelper;
use Boundwize\JsonRecast\Node\Helper\WhitespaceHelper;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeTraverser\NodeChangeSet;
use Boundwize\JsonRecast\Printer\Helper\ContainerPrintHelper;
use Boundwize\JsonRecast\Printer\Helper\ScalarEncodeHelper;
use Boundwize\JsonRecast\Printer\Helper\UnknownNodeHelper;
use SplObjectStorage;

use function abs;
use function array_keys;
use function array_splice;
use function count;
use function intdiv;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function max;
use function min;
use function preg_split;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strspn;
use function substr;
use function substr_compare;
use function trim;
use function usort;

final class JsonPreservingPrinter implements JsonPrinter
{
    /**
     * Splits after every "\n" and after any "\r" not followed by "\n", keeping
     * each line ending attached to its line and every "\r\n" pair intact.
     */
    private const LINE_ENDING_SPLIT_PATTERN = '/(?<=\n)|(?<=\r)(?!\n)/';

    /**
     * Widest space residual carried onto a tab-indented lead for a source line
     * that sits off its space-unit indent grid. The reader's tab rendering
     * width is unknowable, so a wider run glued onto tabs (e.g. the 7-space
     * remainder of an 8-space unit) can overtake the next tab stop and print
     * the misaligned line visually deeper than its aligned siblings; a residual
     * no wider than the narrowest common tab width (2) can never pass a tab
     * stop. Residuals within the cap keep their bytes — and their exact
     * space->tab->space round trip — while wider ones are truncated, trading
     * byte reversibility for correct nesting order at every tab width.
     */
    private const MAXIMUM_TAB_RESIDUAL_LENGTH = 2;

    private readonly ?string $indent;

    /** @var positive-int */
    private readonly int $maximumDepth;

    /**
     * Change results memoized per node for the duration of a single print()
     * run: isChanged() walks the node's whole subtree, so without memoization
     * every level of printing re-walks the subtrees beneath it.
     *
     * @var SplObjectStorage<NodeJson, bool>
     */
    private SplObjectStorage $memoizedChangeResults;

    private bool $printingDocument = false;

    public function __construct(
        private readonly ?NodeChangeSet $nodeChangeSet = null,
        ?string $indent = null,
        int $maximumDepth = MaximumDepthGuard::DEFAULT_MAXIMUM_DEPTH,
    ) {
        $this->indent                = $indent === null ? null : IndentGuard::validateIndent($indent);
        $this->maximumDepth          = MaximumDepthGuard::validateMaximumDepth($maximumDepth);
        $this->memoizedChangeResults = new SplObjectStorage();
    }

    public function print(NodeJson $nodeJson): string
    {
        NodeTreeGuard::guardPrintableRoot($nodeJson);
        NodeTreeGuard::guard($nodeJson, $this->maximumDepth);

        $nodeNewline = $nodeJson->getAttribute(NodeAttributes::NEWLINE);
        $newline     = is_string($nodeNewline) ? NewlineGuard::validateNewline($nodeNewline) : "\n";
        $nodeIndent  = $nodeJson->getAttribute(NodeAttributes::INDENT);
        $indent      = $this->indent
            ?? (is_string($nodeIndent) ? IndentGuard::validateIndent($nodeIndent) : '    ');

        $this->printingDocument = $nodeJson instanceof JsonDocument;

        try {
            return $this->printNode($nodeJson, new PrintContext($indent, $newline), depth: 0);
        } finally {
            // Results memoized during this run must not leak into the next one
            // (the tree or change set may be mutated in between), and dropping
            // them also releases the node references they hold.
            $this->memoizedChangeResults = new SplObjectStorage();
            $this->printingDocument      = false;
        }
    }

    private function printNode(
        NodeJson $nodeJson,
        PrintContext $printContext,
        int $depth,
    ): string {
        if ($nodeJson instanceof JsonDocument) {
            return $this->printDocument($nodeJson, $printContext, $depth);
        }

        if (! $this->isChanged($nodeJson)) {
            $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            // isChanged() already compares a scalar node against its token, so
            // an unchanged node can never carry a mutated scalar value here.
            if (is_string($originalText)) {
                return $this->reindentOriginalText($nodeJson, $originalText, $printContext);
            }
        }

        return match (true) {
            $nodeJson instanceof ObjectNode, $nodeJson instanceof ArrayNode => $this->printContainer(
                $nodeJson,
                $printContext,
                $depth,
            ),
            $nodeJson instanceof StringNode => $this->printStringPreserving($nodeJson),
            $nodeJson instanceof NumberNode => ScalarEncodeHelper::encodeNumber($nodeJson->rawValue),
            $nodeJson instanceof BooleanNode => $nodeJson->value ? 'true' : 'false',
            $nodeJson instanceof NullNode => 'null',
            default => $this->printUnknownNode($nodeJson, $printContext, $depth),
        };
    }

    /**
     * A node kind outside the built-in set exposes no structure to assemble text
     * from, so the printer can do nothing but emit its recorded text verbatim
     * into a value position. Text that is not a JSON value there cannot stand
     * for the node, and how deep the node sits is part of that question: the
     * tree guard cannot see inside such a node, so its text is the one way a
     * printable tree can outgrow the maximum depth it must stay parseable at.
     */
    private function printUnknownNode(NodeJson $nodeJson, PrintContext $printContext, int $depth): string
    {
        return $this->reindentOriginalText(
            $nodeJson,
            $this->unknownNodeText($nodeJson, $depth),
            $printContext,
        );
    }

    /**
     * The recorded text an unknown node stands for, refused unless it can hold
     * the value position the node occupies. Used by the inline printer too, so
     * that compacting a container never lowers this bar.
     */
    private function unknownNodeText(NodeJson $nodeJson, int $depth): string
    {
        return UnknownNodeHelper::valueText($nodeJson, $this->maximumDepth, $depth);
    }

    private function printDocument(
        JsonDocument $jsonDocument,
        PrintContext $printContext,
        int $depth,
    ): string {
        $originalNewline = $this->originalDocumentNewline($jsonDocument);

        $beforeValue = $this->convertNewlineStyle($jsonDocument->beforeValue, $originalNewline, $printContext);
        $afterValue  = $this->convertNewlineStyle($jsonDocument->afterValue, $originalNewline, $printContext);

        $valuePrintContext = $printContext->withIndentation(
            WhitespaceHelper::leadingIndentationOnLastLine($beforeValue),
        );
        $output            = $beforeValue
            . $this->printNode($jsonDocument->value, $valuePrintContext, $depth)
            . $afterValue;

        if (
            $jsonDocument->getAttribute(NodeAttributes::TRAILING_NEWLINE) === true
            && ! str_ends_with($output, "\n")
            && ! str_ends_with($output, "\r")
        ) {
            $output .= $printContext->newline;
        } elseif (
            $jsonDocument->getAttribute(NodeAttributes::TRAILING_NEWLINE) === false
            && $this->hasOriginalTrailingNewline($jsonDocument)
        ) {
            $output = rtrim($output, "\r\n");
        }

        return $output;
    }

    private function printContainer(
        ArrayNode|ObjectNode $containerNode,
        PrintContext $printContext,
        int $depth,
    ): string {
        array_splice($containerNode->items, 0, 0);

        $printedChangedItemValues = [];
        $itemLayouts              = [];
        $shouldPrintBestEffort    = $this->shouldPrintContainerBestEffort($containerNode)
            || $this->shouldPrintInsertedMultilineItemsBestEffort($containerNode);

        if (! $shouldPrintBestEffort) {
            $itemLayouts = $this->resolveContainerItemLayouts($containerNode, $printContext);

            [$shouldPrintBestEffort, $printedChangedItemValues] = $this->printChangedItemValues(
                $containerNode,
                $itemLayouts,
                $depth,
            );
        }

        if ($shouldPrintBestEffort) {
            return $this->printContainerBestEffort(
                $containerNode,
                $printContext,
                $depth,
                $printedChangedItemValues,
            );
        }

        if ($containerNode->items === []) {
            return $this->printEmptyContainer($containerNode, $printContext);
        }

        $output    = ContainerPrintHelper::openingDelimiter($containerNode);
        $lastIndex = count($containerNode->items) - 1;

        foreach ($containerNode->items as $i => $item) {
            [$beforeItem, $afterValue, $itemPrintContext] = $itemLayouts[$i];

            $output .= $item instanceof ObjectItemNode
                ? $this->printObjectItemPreserving(
                    $item,
                    $itemPrintContext,
                    $beforeItem,
                    $afterValue,
                    $depth + 1,
                    $printedChangedItemValues[$i] ?? null,
                )
                : $this->printArrayItemPreserving(
                    $item,
                    $itemPrintContext,
                    $beforeItem,
                    $afterValue,
                    $depth + 1,
                    $printedChangedItemValues[$i] ?? null,
                );

            if ($i < $lastIndex) {
                $output .= ',';
            }
        }

        return $output . ContainerPrintHelper::closingDelimiter($containerNode);
    }

    /**
     * @param array<int, string> $printedChangedItemValues
     */
    private function printContainerBestEffort(
        ArrayNode|ObjectNode $containerNode,
        PrintContext $printContext,
        int $depth,
        array $printedChangedItemValues,
    ): string {
        if ($containerNode->items === []) {
            return $this->printEmptyContainer($containerNode, $printContext);
        }

        return ContainerPrintHelper::printItemsOnOwnLines(
            $containerNode,
            $printContext,
            fn (
                ArrayItemNode|ObjectItemNode $item,
                PrintContext $childPrintContext,
                int $i,
            ): string => $this->printItemBestEffort(
                $item,
                $childPrintContext,
                $depth + 1,
                $printedChangedItemValues[$i] ?? null,
            ),
        );
    }

    private function printItemBestEffort(
        ArrayItemNode|ObjectItemNode $item,
        PrintContext $printContext,
        int $depth,
        ?string $printedValue,
    ): string {
        if ($item instanceof ObjectItemNode) {
            return $this->printObjectItemBestEffort($item, $printContext, $depth, $printedValue);
        }

        return $printedValue ?? $this->printNode($item->value, $printContext, $depth);
    }

    private function printEmptyContainer(ArrayNode|ObjectNode $containerNode, PrintContext $printContext): string
    {
        $beforeClose = $this->reindentWhitespaceBeforeNode(
            $containerNode,
            $this->adoptNewlineStyle($this->beforeClose($containerNode), $containerNode, $printContext),
            $printContext,
        );

        return ContainerPrintHelper::openingDelimiter($containerNode)
            . $beforeClose
            . ContainerPrintHelper::closingDelimiter($containerNode);
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
        string $beforeKey,
        string $afterValue,
        int $depth,
        ?string $printedValue,
    ): string {
        if (
            $beforeKey === $objectItemNode->beforeKey
            && $afterValue === $objectItemNode->afterValue
            && ! $this->isChanged($objectItemNode)
        ) {
            $originalText = $objectItemNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (is_string($originalText)) {
                return $this->reindentOriginalText($objectItemNode, $originalText, $printContext);
            }
        }

        $separator = $this->adoptNewlineStyle(
            $this->objectItemSeparator($objectItemNode),
            $objectItemNode,
            $printContext,
        );

        return $beforeKey
            . $this->printNode($objectItemNode->key, $printContext, $depth)
            . $separator
            // An already printed value needs no context of its own, so the
            // value's context is resolved only where it is actually consumed.
            . ($printedValue
                ?? $this->printNode(
                    $objectItemNode->value,
                    $this->valuePrintContext($objectItemNode, $printContext, $separator),
                    $depth,
                ))
            . $afterValue;
    }

    private function printObjectItemBestEffort(
        ObjectItemNode $objectItemNode,
        PrintContext $printContext,
        int $depth,
        ?string $printedValue,
    ): string {
        $separator = $this->adoptNewlineStyle(
            $this->objectItemSeparator($objectItemNode),
            $objectItemNode,
            $printContext,
        );

        return $this->printNode($objectItemNode->key, $printContext, $depth)
            . $separator
            . ($printedValue
                ?? $this->printNode(
                    $objectItemNode->value,
                    $this->valuePrintContext($objectItemNode, $printContext, $separator),
                    $depth,
                ));
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
        string $beforeValue,
        string $afterValue,
        int $depth,
        ?string $printedValue,
    ): string {
        if (
            $beforeValue === $arrayItemNode->beforeValue
            && $afterValue === $arrayItemNode->afterValue
            && ! $this->isChanged($arrayItemNode)
        ) {
            $originalText = $arrayItemNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (is_string($originalText)) {
                return $this->reindentOriginalText($arrayItemNode, $originalText, $printContext);
            }
        }

        return $beforeValue
            . ($printedValue
                ?? $this->printNode($arrayItemNode->value, $printContext, $depth))
            . $afterValue;
    }

    /**
     * @param list<ArrayItemNode|ObjectItemNode> $items
     * @return array{string, string}
     */
    private function getItemLayout(
        array $items,
        int $index,
        ArrayItemNode|ObjectItemNode $layoutDonor,
        string $containerAfterOpen,
        string $containerBeforeClose,
    ): array {
        $lastIndex  = count($items) - 1;
        $afterValue = $index === $lastIndex ? $containerBeforeClose : $layoutDonor->afterValue;

        // Only the first item sits against the opening delimiter; every later
        // one leads with its layout donor's own whitespace, which for an item
        // donating to itself is simply the whitespace it already carries.
        $beforeValue = $index === 0
            ? $containerAfterOpen
            : $this->beforeItem($layoutDonor);

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
     * @return array<int, array{string, string, PrintContext}>
     */
    private function resolveContainerItemLayouts(
        ArrayNode|ObjectNode $containerNode,
        PrintContext $printContext,
    ): array {
        $itemLayouts          = [];
        $itemsInOriginalOrder = $this->getItemsInOriginalOrder($containerNode->items);
        $interiorShift        = $this->resolveInteriorItemShift($containerNode, $printContext);
        $childPrintContext    = $printContext->next();
        $containerAfterOpen   = $this->adoptNewlineStyle(
            $this->afterOpen($containerNode),
            $containerNode,
            $printContext,
        );
        $containerBeforeClose = $this->reindentWhitespaceBeforeNode(
            $containerNode,
            $this->adoptNewlineStyle($this->beforeClose($containerNode), $containerNode, $printContext),
            $printContext,
        );

        foreach ($containerNode->items as $i => $item) {
            [$beforeItem, $afterValue] = $this->getItemLayout(
                $containerNode->items,
                $i,
                $itemsInOriginalOrder[$i],
                $containerAfterOpen,
                $containerBeforeClose,
            );

            $newlineSource = $this->newlineStyleSource($item, $containerNode);
            $afterValue    = $this->adoptNewlineStyle($afterValue, $newlineSource, $printContext);

            $beforeItem = $this->adoptNewlineStyle($beforeItem, $newlineSource, $printContext);
            $beforeItem = $interiorShift !== null
                ? $this->shiftWhitespaceBeforeNode($beforeItem, $interiorShift)
                : $this->reindentWhitespaceBeforeNode($item, $beforeItem, $childPrintContext);

            $itemPrintContext = $childPrintContext->afterText($beforeItem);
            $itemLayouts[$i]  = [$beforeItem, $afterValue, $itemPrintContext];
        }

        return $itemLayouts;
    }

    private function valuePrintContext(
        ArrayItemNode|ObjectItemNode $item,
        PrintContext $printContext,
        ?string $separator = null,
    ): PrintContext {
        if ($item instanceof ArrayItemNode) {
            return $printContext;
        }

        return $printContext->afterText($separator ?? $this->objectItemSeparator($item));
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
        // Only whitespace that duplicates the container's closing whitespace is
        // a candidate for normalization, so everything else leaves immediately.
        if ($containerBeforeClose === '' || $afterValue !== $containerBeforeClose) {
            return $afterValue;
        }

        if (StartOffsetHelper::isSyntheticNode($items[$index + 1])) {
            return $this->findSeparatorBeforeIndex($items, $index, $containerBeforeClose);
        }

        if (! StartOffsetHelper::isSyntheticNode($itemNode)) {
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

    private function shouldPrintInsertedMultilineItemsBestEffort(ArrayNode|ObjectNode $containerNode): bool
    {
        if ($this->hasContainerMultilineEdgeWhitespace($containerNode)) {
            return false;
        }

        foreach ($containerNode->items as $item) {
            if (! StartOffsetHelper::isSyntheticNode($item)) {
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
     * @param array<int, array{string, string, PrintContext}> $itemLayouts
     * @return array{bool, array<int, string>}
     */
    private function printChangedItemValues(
        ArrayNode|ObjectNode $containerNode,
        array $itemLayouts,
        int $depth,
    ): array {
        $printedValues = [];

        // Both of these describe the container rather than any one item, so
        // they are resolved once here instead of per changed item. A directly
        // printed changed container keeps the established best-effort multiline
        // layout; whole documents and nested inline containers compact whatever
        // value can be rendered on one line, so that its expansion does not
        // force untouched content to reflow.
        $hasMultilineEdgeWhitespace    = $this->hasContainerMultilineEdgeWhitespace($containerNode);
        $compactsInlinePrintableValues = ($this->printingDocument || $depth > 0)
            && ! $hasMultilineEdgeWhitespace;

        foreach ($containerNode->items as $i => $item) {
            if (! $this->isChanged($item)) {
                continue;
            }

            $printedValue      = $compactsInlinePrintableValues && $this->isInlinePrintableValue($item->value)
                ? $this->printNodeInline($item->value, $depth + 1)
                : $this->printNode(
                    $item->value,
                    $this->valuePrintContext($item, $itemLayouts[$i][2]),
                    $depth + 1,
                );
            $printedValues[$i] = $printedValue;

            if (str_contains($printedValue, "\n") || str_contains($printedValue, "\r")) {
                if ($this->itemWasOriginallyMultiline($item)) {
                    continue;
                }

                return [! $hasMultilineEdgeWhitespace, $printedValues];
            }
        }

        return [false, $printedValues];
    }

    /**
     * Compacting only ever applies to a container value: a scalar already
     * occupies the single line the inline printer produces.
     */
    private function isInlinePrintableValue(NodeJson $nodeJson): bool
    {
        return ($nodeJson instanceof ArrayNode || $nodeJson instanceof ObjectNode)
            && $this->isInlinePrintable($nodeJson);
    }

    /**
     * Whether the inline printer can render this node on the single line it
     * exists to produce. A wholly new node always can: it carries no source
     * layout that compacting could lose. A node reused from a parsed document
     * can only when its own source text already occupies one line — which every
     * scalar token does, so source metadata on a reused scalar never by itself
     * forces a newly inserted container to expand and reflow its host.
     */
    private function isInlinePrintable(NodeJson $nodeJson): bool
    {
        return match (true) {
            $nodeJson instanceof ObjectNode, $nodeJson instanceof ArrayNode => $this->isInlinePrintableContainer(
                $nodeJson,
            ),
            $nodeJson instanceof ObjectItemNode => $this->isInlinePrintable($nodeJson->key)
                && $this->isInlinePrintable($nodeJson->value),
            $nodeJson instanceof ArrayItemNode => $this->isInlinePrintable($nodeJson->value),
            // No JSON scalar token spans lines: a string escapes its newlines
            // and no other literal admits one. Whether the token came from a
            // parsed document therefore cannot decide the enclosing layout, and
            // the inline printer reuses its source spelling either way.
            $nodeJson instanceof StringNode,
            $nodeJson instanceof NumberNode,
            $nodeJson instanceof BooleanNode,
            $nodeJson instanceof NullNode => true,
            // A node kind outside the built-in set exposes no structure to
            // assemble a line from, so its recorded text is the only thing the
            // printer can emit for it — and that fits the line exactly when it
            // holds no line ending. Whether the text is a JSON value at all is
            // settled where it prints, here as on the normal path.
            default => $this->hasSingleLineOriginalText($nodeJson),
        };
    }

    private function isInlinePrintableContainer(ArrayNode|ObjectNode $containerNode): bool
    {
        // Recorded source text is itself what makes a container non-synthetic
        // (mutations mark synthetic nodes with a null original text attribute),
        // and a reused parsed container prints as exactly that text. So it fits
        // the single line when the text holds no line ending. Text a later
        // mutation left stale says nothing about what the container would print
        // as, so a changed one never qualifies.
        if ($this->hasSingleLineOriginalText($containerNode)) {
            return ! $this->isChanged($containerNode);
        }

        // Parsed but text-less, so nothing records how it looked: only a wholly
        // new container can be assembled onto the line from its items alone.
        return StartOffsetHelper::isSyntheticNode($containerNode)
            && $this->areInlinePrintable($containerNode->items);
    }

    /**
     * Whether the node records source text that already occupies a single line,
     * which is what lets the inline printer emit that text verbatim instead of
     * reassembling the node from its structure.
     */
    private function hasSingleLineOriginalText(NodeJson $nodeJson): bool
    {
        $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        return is_string($originalText)
            && ! str_contains($originalText, "\n")
            && ! str_contains($originalText, "\r");
    }

    /**
     * @param list<NodeJson> $nodes
     */
    private function areInlinePrintable(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (! $this->isInlinePrintable($node)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Renders onto a single line any node isInlinePrintable() admitted — wholly
     * new, reused from a parsed document, or of a kind outside the built-in set
     * — reusing recorded source text wherever one is carried.
     *
     * $depth tracks the printed node's position exactly as printNode() does, so
     * that text admitted here is held to the same depth bound it would be on
     * the normal path. Compacting moves no node up or down the tree.
     */
    private function printNodeInline(NodeJson $nodeJson, int $depth): string
    {
        return match (true) {
            $nodeJson instanceof ObjectNode, $nodeJson instanceof ArrayNode => $this->printContainerInline(
                $nodeJson,
                $depth,
            ),
            $nodeJson instanceof ObjectItemNode => $this->printNodeInline($nodeJson->key, $depth)
                . $this->inlineObjectItemSeparator($nodeJson)
                . $this->printNodeInline($nodeJson->value, $depth),
            $nodeJson instanceof ArrayItemNode => $this->printNodeInline($nodeJson->value, $depth),
            // Reuses the source token of a reused parsed string, so compacting
            // the container around it does not respell its escapes.
            $nodeJson instanceof StringNode => $this->printStringPreserving($nodeJson),
            // rawValue is the source lexeme of a parsed number, so the spelling
            // it was written with survives here too.
            $nodeJson instanceof NumberNode => ScalarEncodeHelper::encodeNumber($nodeJson->rawValue),
            $nodeJson instanceof BooleanNode => $nodeJson->value ? 'true' : 'false',
            $nodeJson instanceof NullNode => 'null',
            default => $this->unknownNodeText($nodeJson, $depth),
        };
    }

    private function printContainerInline(ArrayNode|ObjectNode $containerNode, int $depth): string
    {
        $originalText = $containerNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        // Recorded source text means the container came from a parsed document,
        // and isInlinePrintableContainer() admitted it only because that text
        // already fits one line. Reuse it: reassembling the container from its
        // structure would drop the interior spacing the source carries.
        if (is_string($originalText)) {
            return $originalText;
        }

        return $this->printSyntheticContainerInline($containerNode, $depth);
    }

    private function inlineObjectItemSeparator(ObjectItemNode $objectItemNode): string
    {
        $separator = $this->objectItemSeparator($objectItemNode);

        // Inline compaction must keep the value on a single line, so authored
        // multiline spacing around the colon cannot be carried over here.
        if (str_contains($separator, "\n") || str_contains($separator, "\r")) {
            return ': ';
        }

        return $separator;
    }

    private function printSyntheticContainerInline(ArrayNode|ObjectNode $containerNode, int $depth): string
    {
        array_splice($containerNode->items, 0, 0);

        $output = ContainerPrintHelper::openingDelimiter($containerNode);

        foreach ($containerNode->items as $i => $item) {
            if ($i > 0) {
                $output .= ', ';
            }

            // An item sits one level below the container holding it, matching
            // how printContainer() descends.
            $output .= $this->printNodeInline($item, $depth + 1);
        }

        return $output . ContainerPrintHelper::closingDelimiter($containerNode);
    }

    private function itemWasOriginallyMultiline(ArrayItemNode|ObjectItemNode $item): bool
    {
        $originalText = $item->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        return is_string($originalText)
            && (str_contains($originalText, "\n") || str_contains($originalText, "\r"));
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
        $lastNewlinePosition = WhitespaceHelper::lastNewlinePosition($whitespace);

        if ($lastNewlinePosition < 0) {
            return $whitespace;
        }

        $leadingWhitespace = substr($whitespace, $lastNewlinePosition + 1);

        return substr($whitespace, 0, $lastNewlinePosition + 1)
            . $this->reindentLeadingWhitespace(
                $nodeJson,
                $leadingWhitespace,
                $printContext,
                $this->resolveOriginalBaseIndentation($nodeJson, $leadingWhitespace),
            );
    }

    private function shiftWhitespaceBeforeNode(string $whitespace, int $interiorShift): string
    {
        $lastNewlinePosition = WhitespaceHelper::lastNewlinePosition($whitespace);

        if ($lastNewlinePosition < 0) {
            return $whitespace;
        }

        return substr($whitespace, 0, $lastNewlinePosition + 1)
            . substr($whitespace, $lastNewlinePosition + 1 - $interiorShift);
    }

    /**
     * Line endings from parsed text are converted when its detected style differs
     * from the requested print style, mirroring how leading whitespace is converted
     * to the requested indent unit. This happens for cross-document grafts and
     * direct changes to document NEWLINE metadata. Otherwise the bytes pass through
     * untouched, including minority endings in a mixed-EOL source.
     */
    private function adoptNewlineStyle(
        string $text,
        NodeJson $nodeJson,
        PrintContext $printContext,
    ): string {
        // Text holding no line ending converts to itself whatever the node's
        // style is, so the attribute behind that style need not be read at all.
        if (! str_contains($text, "\n") && ! str_contains($text, "\r")) {
            return $text;
        }

        return $this->convertNewlineStyle(
            $text,
            $nodeJson->getAttribute(NodeAttributes::NEWLINE),
            $printContext,
        );
    }

    private function convertNewlineStyle(
        string $text,
        mixed $originalNewline,
        PrintContext $printContext,
    ): string {
        if (! str_contains($text, "\n") && ! str_contains($text, "\r")) {
            return $text;
        }

        if (! is_string($originalNewline) || $originalNewline === $printContext->newline) {
            return $text;
        }

        $normalized = WhitespaceHelper::normalizeNewlines($text);

        return $printContext->newline === "\n"
            ? $normalized
            : str_replace("\n", $printContext->newline, $normalized);
    }

    /**
     * The newline style the document framing originated with. Detected from
     * the framing source bytes rather than the NEWLINE attribute (which the
     * user may have changed and print() adopts as the target style) or the root
     * value node (which may have been grafted from another document and must
     * not rewrite the host framing). A standalone synthetic document has no
     * source; its framing then follows the root value's parsed style, if any.
     */
    private function originalDocumentNewline(JsonDocument $jsonDocument): mixed
    {
        $source = $jsonDocument->getAttribute(NodeAttributes::SOURCE);

        if (is_string($source)) {
            return WhitespaceHelper::detectNewline($source);
        }

        return $jsonDocument->value->getAttribute(NodeAttributes::NEWLINE);
    }

    /**
     * A synthetic item carries no NEWLINE of its own but may inherit layout
     * whitespace from its container's parsed items, so the container decides
     * the newline style for it.
     */
    private function newlineStyleSource(
        ArrayItemNode|ObjectItemNode $item,
        ArrayNode|ObjectNode $containerNode,
    ): NodeJson {
        return is_string($item->getAttribute(NodeAttributes::NEWLINE)) ? $item : $containerNode;
    }

    private function resolveInteriorItemShift(
        ArrayNode|ObjectNode $containerNode,
        PrintContext $printContext,
    ): ?int {
        $originalDepth = $containerNode->getAttribute(NodeAttributes::DEPTH);

        if (! is_int($originalDepth)) {
            return null;
        }

        $delta          = $printContext->level() - $originalDepth;
        $originalIndent = $containerNode->getAttribute(NodeAttributes::INDENT);

        if (! $this->canShiftOffGridInterior($originalIndent, $printContext->indentUnit(), $delta)) {
            return null;
        }

        $itemWhitespace = [$this->afterOpen($containerNode)];
        foreach ($containerNode->items as $item) {
            $itemWhitespace[] = $this->beforeItem($item);
        }

        $itemLeads = [];
        foreach ($itemWhitespace as $whitespace) {
            $lastNewlinePosition = WhitespaceHelper::lastNewlinePosition($whitespace);

            if ($lastNewlinePosition < 0) {
                continue;
            }

            $itemLeads[] = substr($whitespace, $lastNewlinePosition + 1);
        }

        return $this->resolveOffGridInteriorShift(
            $originalIndent,
            $itemLeads,
            $printContext->indentUnit(),
            $delta,
        );
    }

    private function reindentLeadingWhitespace(
        NodeJson $nodeJson,
        string $leadingWhitespace,
        PrintContext $printContext,
        ?string $originalBaseIndentation,
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
            $targetBaseIndentation = '';

            if (
                $originalBaseIndentation !== null
                && str_starts_with($leadingWhitespace, $originalBaseIndentation)
            ) {
                $leadingWhitespace     = substr($leadingWhitespace, strlen($originalBaseIndentation));
                $targetBaseIndentation = $this->targetBaseIndentation($printContext);
            }

            return $targetBaseIndentation . $this->reindentLeadingWhitespaceUnit(
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

    private function resolveOriginalBaseIndentation(NodeJson $nodeJson, string $leadingWhitespace): ?string
    {
        $originalDepth  = $nodeJson->getAttribute(NodeAttributes::DEPTH);
        $originalIndent = $nodeJson->getAttribute(NodeAttributes::INDENT);

        if (! is_int($originalDepth) || ! is_string($originalIndent) || $originalIndent === '') {
            return null;
        }

        $structuralIndentation = str_repeat($originalIndent, $originalDepth);

        if (! str_ends_with($leadingWhitespace, $structuralIndentation)) {
            return null;
        }

        return substr($leadingWhitespace, 0, strlen($leadingWhitespace) - strlen($structuralIndentation));
    }

    private function targetBaseIndentation(PrintContext $printContext): string
    {
        $indentation           = $printContext->indentation();
        $structuralIndentation = str_repeat($printContext->indentUnit(), $printContext->level());

        if (! str_ends_with($indentation, $structuralIndentation)) {
            return '';
        }

        return substr($indentation, 0, strlen($indentation) - strlen($structuralIndentation));
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

        if (str_contains($targetIndent, "\t")) {
            $wholeIndentLevel       = intdiv($leadingWhitespaceLength, $originalIndentLength);
            $targetWholeIndentLevel = $wholeIndentLevel + $delta;

            if ($targetWholeIndentLevel < 0) {
                return '';
            }

            $residualOffset     = $wholeIndentLevel * $originalIndentLength;
            $residualWhitespace = substr($leadingWhitespace, $residualOffset);

            // A space residual glued verbatim onto tabs is in source-space
            // metric and overtakes the next tab stop whenever tabs render
            // narrower than the source unit, printing the misaligned line
            // visually deeper than its aligned siblings.
            if (
                ! str_contains($originalIndent, "\t")
                && strspn($residualWhitespace, ' ') === strlen($residualWhitespace)
            ) {
                return str_repeat($targetIndent, $targetWholeIndentLevel)
                    . substr($residualWhitespace, 0, self::MAXIMUM_TAB_RESIDUAL_LENGTH);
            }

            return str_repeat($targetIndent, $targetWholeIndentLevel)
                . $residualWhitespace;
        }

        $targetLevel  = $indentLevel + $delta;
        $targetPrefix = str_repeat($targetIndent, max($targetLevel, 0));

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
        $originalText  = $this->adoptNewlineStyle($originalText, $nodeJson, $printContext);
        $originalDepth = $nodeJson->getAttribute(NodeAttributes::DEPTH);

        if (! is_int($originalDepth)) {
            return $originalText;
        }

        $delta          = $printContext->level() - $originalDepth;
        $originalIndent = $nodeJson->getAttribute(NodeAttributes::INDENT);

        // The node sits at its original depth and the target indent unit matches
        // the source, so reindentLeadingWhitespace() would hand every lead back
        // untouched and the split lines would rejoin into $originalText.
        if ($delta === 0 && is_string($originalIndent) && $originalIndent === $printContext->indentUnit()) {
            return $originalText;
        }

        /** @var non-empty-list<string> $lines */
        $lines = preg_split(self::LINE_ENDING_SPLIT_PATTERN, $originalText);

        $output                  = $lines[0];
        $count                   = count($lines);
        $interiorShift           = null;
        $originalBaseIndentation = null;

        if ($nodeJson instanceof ObjectNode || $nodeJson instanceof ArrayNode) {
            $closingLine             = $lines[$count - 1];
            $closingLeadingLength    = strspn($closingLine, " \t");
            $originalBaseIndentation = $this->resolveOriginalBaseIndentation(
                $nodeJson,
                substr($closingLine, 0, $closingLeadingLength),
            );
        }

        if ($this->canShiftOffGridInterior($originalIndent, $printContext->indentUnit(), $delta)) {
            $interiorLeads = [];
            for ($i = 1; $i < $count - 1; $i++) {
                if (trim($lines[$i]) === '') {
                    continue;
                }

                $interiorLeads[] = substr($lines[$i], 0, strspn($lines[$i], " \t"));
            }

            $interiorShift = $this->resolveOffGridInteriorShift(
                $originalIndent,
                $interiorLeads,
                $printContext->indentUnit(),
                $delta,
            );
        }

        for ($i = 1; $i < $count; $i++) {
            $line                    = $lines[$i];
            $leadingWhitespaceLength = strspn($line, " \t");

            // A line holding nothing but its ending has no indentation to scale,
            // and handing it one would introduce trailing whitespace the source
            // never had. A whitespace-only line, by contrast, carries real
            // indentation that has to move with the lines around it, so it falls
            // through and is rescaled like any other.
            if ($leadingWhitespaceLength === 0 && trim($line) === '') {
                $output .= $line;

                continue;
            }

            $leadingWhitespace = substr($line, 0, $leadingWhitespaceLength);

            // Interior lines off the original indent grid carry intentional relative
            // indentation that per-line level scaling would flatten; shift the whole
            // interior by the depth delta instead. The closing line still scales so
            // the bracket aligns with its container.
            $output .= ($interiorShift !== null && $i < $count - 1
                ? substr($leadingWhitespace, -$interiorShift)
                : $this->reindentLeadingWhitespace(
                    $nodeJson,
                    $leadingWhitespace,
                    $printContext,
                    $originalBaseIndentation,
                ))
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
        string $originalIndent,
        array $interiorLeads,
        string $targetIndent,
        int $delta,
    ): ?int {
        if (
            $interiorLeads === []
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
     * Preconditions for an interior shift that hold before any lead is known:
     * only a node re-indented shallower with a different indent unit can have
     * one. Checked before the leads are collected so the common print path does
     * not scan whitespace for a result that would be discarded.
     *
     * @phpstan-assert-if-true string $originalIndent
     */
    private function canShiftOffGridInterior(mixed $originalIndent, string $targetIndent, int $delta): bool
    {
        return $delta < 0
            && is_string($originalIndent)
            && $originalIndent !== ''
            && $originalIndent !== $targetIndent;
    }

    /**
     * Called only for a negative depth delta, guarded by canShiftOffGridInterior().
     *
     * @param list<string> $leads
     */
    private function hasClampedLeadOffOriginalIndentGrid(
        array $leads,
        string $originalIndent,
        int $delta,
    ): bool {
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

    private function shouldPrintContainerBestEffort(ObjectNode|ArrayNode $nodeJson): bool
    {
        $items = $nodeJson->items;

        if ($this->nodeChangeSet instanceof NodeChangeSet && $this->nodeChangeSet->isChanged($nodeJson)) {
            if (
                $items !== []
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
        /** @var list<float> $startOffsets */
        $startOffsets        = [];
        $previousStartOffset = null;
        $isInOriginalOrder   = true;

        foreach ($items as $i => $item) {
            $startOffset = $item->getAttribute(NodeAttributes::START_OFFSET);

            if (! is_int($startOffset) && ! is_float($startOffset)) {
                $startOffset = $this->getSyntheticStartOffset($items, $i);
            }

            $startOffset = (float) $startOffset;

            if ($previousStartOffset !== null && $startOffset < $previousStartOffset) {
                $isInOriginalOrder = false;
            }

            $previousStartOffset = $startOffset;
            $startOffsets[]      = $startOffset;
        }

        // Non-decreasing offsets sort to themselves (ties keep index order).
        if ($isInOriginalOrder) {
            return $items;
        }

        // Only the indexes are reordered: pairing each offset with its node up
        // front would allocate a record per item that the common in-order
        // return above discards.
        $indexes = array_keys($items);

        usort(
            $indexes,
            static fn (int $left, int $right): int => $startOffsets[$left] <=> $startOffsets[$right]
                ?: $left <=> $right,
        );

        /** @var list<T> $itemsInOriginalOrder */
        $itemsInOriginalOrder = [];

        foreach ($indexes as $index) {
            $itemsInOriginalOrder[] = $items[$index];
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
        if ($this->memoizedChangeResults->offsetExists($nodeJson)) {
            return $this->memoizedChangeResults[$nodeJson];
        }

        $isChanged = $this->resolveIsChanged($nodeJson);

        $this->memoizedChangeResults[$nodeJson] = $isChanged;

        return $isChanged;
    }

    private function resolveIsChanged(NodeJson $nodeJson): bool
    {
        if ($this->nodeChangeSet instanceof NodeChangeSet && $this->nodeChangeSet->isChanged($nodeJson)) {
            return true;
        }

        $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        if (! is_string($originalText)) {
            return true;
        }

        // The two groups are disjoint, so routing once beats asking every node
        // all three questions: a scalar holds no assembled text and no child to
        // recurse into, and a structural node carries no scalar value of its own.
        return match (true) {
            $nodeJson instanceof StringNode => $this->hasStringValueChanged($nodeJson, $originalText),
            $nodeJson instanceof NumberNode => $originalText !== $nodeJson->rawValue,
            $nodeJson instanceof BooleanNode => ($nodeJson->value ? 'true' : 'false') !== $originalText,
            $nodeJson instanceof NullNode => false,
            $nodeJson instanceof ObjectItemNode,
            $nodeJson instanceof ArrayItemNode,
            $nodeJson instanceof ObjectNode,
            $nodeJson instanceof ArrayNode,
            $nodeJson instanceof JsonDocument => $this->hasStaleOriginalText($nodeJson, $originalText)
                || $this->hasChangedDescendant($nodeJson),
            // A node kind outside the built-in set exposes no structure, so it
            // offers neither text to assemble nor a child to recurse into, and
            // nothing here could call it unchanged. Whether its recorded text
            // can stand for it is settled where it prints, at the depth it sits
            // at — naming the built-in kinds leaves that the only open case.
            default => true,
        };
    }

    private function hasOriginalTrailingNewline(JsonDocument $jsonDocument): bool
    {
        $source = $jsonDocument->getAttribute(NodeAttributes::SOURCE);

        return is_string($source)
            && (str_ends_with($source, "\n") || str_ends_with($source, "\r"));
    }

    private function hasStaleOriginalText(
        JsonDocument|ObjectNode|ArrayNode|ObjectItemNode|ArrayItemNode $nodeJson,
        string $originalText,
    ): bool {
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
            // The remaining kind, ArrayItemNode: every kind that reaches here
            // assembles text from structure the printer knows.
            default => $nodeJson->beforeValue
                . $this->getOriginalText($nodeJson->value)
                . $nodeJson->afterValue,
        };

        return $reconstructedOriginalText !== $originalText;
    }

    private function getOriginalText(NodeJson $nodeJson): string
    {
        $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        return is_string($originalText) ? $originalText : '';
    }

    private function reconstructOriginalContainerText(ObjectNode|ArrayNode $containerNode): string
    {
        if ($containerNode->items === []) {
            return ContainerPrintHelper::openingDelimiter($containerNode)
                . $this->beforeClose($containerNode)
                . ContainerPrintHelper::closingDelimiter($containerNode);
        }

        $output    = ContainerPrintHelper::openingDelimiter($containerNode);
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

        return $output . ContainerPrintHelper::closingDelimiter($containerNode);
    }

    private function reconstructOriginalContainerItemText(ObjectItemNode|ArrayItemNode $item): string
    {
        return match (true) {
            $item instanceof ObjectItemNode => $this->getOriginalText($item->key)
                . $item->betweenKeyAndColon
                . ':'
                . $item->betweenColonAndValue
                . $this->getOriginalText($item->value),
            // The remaining kind, ArrayItemNode: it contributes only its value.
            default => $this->getOriginalText($item->value),
        };
    }

    private function hasStringValueChanged(StringNode $stringNode, string $originalText): bool
    {
        $value = json_decode($originalText, true, $this->maximumDepth);

        return $value !== $stringNode->value;
    }

    private function hasChangedDescendant(
        JsonDocument|ObjectNode|ArrayNode|ObjectItemNode|ArrayItemNode $nodeJson,
    ): bool {
        if ($nodeJson instanceof JsonDocument) {
            return $this->isChanged($nodeJson->value);
        }

        if ($nodeJson instanceof ObjectItemNode) {
            return $this->isChanged($nodeJson->key) || $this->isChanged($nodeJson->value);
        }

        if ($nodeJson instanceof ArrayItemNode) {
            return $this->isChanged($nodeJson->value);
        }

        return $this->hasChangedContainerItem($nodeJson);
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

    private function printStringPreserving(StringNode $stringNode): string
    {
        $originalText  = $stringNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);
        $originalValue = is_string($originalText)
            ? json_decode($originalText, true, $this->maximumDepth)
            : null;

        // Decoding loses the source escape spelling, so reuse the token only
        // after positively confirming that it still represents the node value.
        if (is_string($originalValue) && $originalValue === $stringNode->value) {
            return $originalText;
        }

        return ScalarEncodeHelper::encodeString($stringNode->value, $this->maximumDepth);
    }
}
