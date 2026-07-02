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
use function is_string;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;

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

    private function printNode(NodeJson $nodeJson, PrintContext $printContext): string
    {
        if (! $this->isChanged($nodeJson)) {
            $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (is_string($originalText)) {
                return $originalText;
            }
        }

        return match (true) {
            $nodeJson instanceof JsonDocument => $this->printDocument($nodeJson, $printContext),
            $nodeJson instanceof ObjectNode => $this->printObject($nodeJson, $printContext),
            $nodeJson instanceof ObjectItemNode => $this->printObjectItemPreserving($nodeJson, $printContext),
            $nodeJson instanceof ArrayNode => $this->printArray($nodeJson, $printContext),
            $nodeJson instanceof ArrayItemNode => $this->printArrayItemPreserving($nodeJson, $printContext),
            $nodeJson instanceof StringNode => $this->encodeString($nodeJson->value),
            $nodeJson instanceof NumberNode => $nodeJson->rawValue,
            $nodeJson instanceof BooleanNode => $nodeJson->value ? 'true' : 'false',
            $nodeJson instanceof NullNode => 'null',
            default => throw new RuntimeException('Unsupported JSON node.'),
        };
    }

    private function printDocument(JsonDocument $jsonDocument, PrintContext $printContext): string
    {
        $output = $jsonDocument->beforeValue
            . $this->printNode($jsonDocument->value, $printContext)
            . $jsonDocument->afterValue;

        if (
            $jsonDocument->afterValue === ''
            && $jsonDocument->getAttribute(NodeAttributes::TRAILING_NEWLINE) === true
        ) {
            $output .= $printContext->newline;
        }

        return $output;
    }

    private function printObject(ObjectNode $objectNode, PrintContext $printContext): string
    {
        if ($this->shouldPrintContainerBestEffort($objectNode, $objectNode->items)) {
            return $this->printObjectBestEffort($objectNode, $printContext);
        }

        $output = '{';

        foreach ($objectNode->items as $i => $item) {
            $output .= $this->printObjectItemPreserving($item, $printContext->next());

            if ($i < count($objectNode->items) - 1) {
                $output .= ',';
            }
        }

        $lastItem = $objectNode->items[count($objectNode->items) - 1];

        if ($lastItem->afterValue !== $objectNode->beforeCloseBrace) {
            $output .= $objectNode->beforeCloseBrace;
        }

        return $output . '}';
    }

    private function printObjectBestEffort(ObjectNode $objectNode, PrintContext $printContext): string
    {
        if ($objectNode->items === []) {
            return $this->printEmptyObject($objectNode);
        }

        $output = '{';

        foreach ($objectNode->items as $i => $item) {
            $output .= $printContext->newline
                . $printContext->childIndentation()
                . $this->printObjectItemBestEffort($item, $printContext->next());

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

    private function printObjectItemPreserving(ObjectItemNode $objectItemNode, PrintContext $printContext): string
    {
        if (! $this->isChanged($objectItemNode)) {
            $originalText = $objectItemNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (is_string($originalText)) {
                return $originalText;
            }
        }

        return $objectItemNode->beforeKey
            . $this->printNode($objectItemNode->key, $printContext)
            . $objectItemNode->betweenKeyAndColon
            . ':'
            . $objectItemNode->betweenColonAndValue
            . $this->printNode($objectItemNode->value, $printContext)
            . $objectItemNode->afterValue;
    }

    private function printObjectItemBestEffort(ObjectItemNode $objectItemNode, PrintContext $printContext): string
    {
        return $this->printNode($objectItemNode->key, $printContext)
            . ': '
            . $this->printNode($objectItemNode->value, $printContext);
    }

    private function printArray(ArrayNode $arrayNode, PrintContext $printContext): string
    {
        if ($this->shouldPrintContainerBestEffort($arrayNode, $arrayNode->items)) {
            return $this->printArrayBestEffort($arrayNode, $printContext);
        }

        $output = '[';

        foreach ($arrayNode->items as $i => $item) {
            $output .= $this->printArrayItemPreserving($item, $printContext->next());

            if ($i < count($arrayNode->items) - 1) {
                $output .= ',';
            }
        }

        $lastItem = $arrayNode->items[count($arrayNode->items) - 1];

        if ($lastItem->afterValue !== $arrayNode->beforeCloseBracket) {
            $output .= $arrayNode->beforeCloseBracket;
        }

        return $output . ']';
    }

    private function printArrayBestEffort(ArrayNode $arrayNode, PrintContext $printContext): string
    {
        if ($arrayNode->items === []) {
            return $this->printEmptyArray($arrayNode);
        }

        $output = '[';

        foreach ($arrayNode->items as $i => $item) {
            $output .= $printContext->newline
                . $printContext->childIndentation()
                . $this->printNode($item->value, $printContext->next());

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

    private function printArrayItemPreserving(ArrayItemNode $arrayItemNode, PrintContext $printContext): string
    {
        if (! $this->isChanged($arrayItemNode)) {
            $originalText = $arrayItemNode->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (is_string($originalText)) {
                return $originalText;
            }
        }

        return $arrayItemNode->beforeValue
            . $this->printNode($arrayItemNode->value, $printContext)
            . $arrayItemNode->afterValue;
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

    private function isChanged(NodeJson $nodeJson): bool
    {
        if ($this->nodeChangeSet instanceof NodeChangeSet && $this->nodeChangeSet->isChanged($nodeJson)) {
            return true;
        }

        if (! $nodeJson->hasAttribute(NodeAttributes::ORIGINAL_TEXT)) {
            return true;
        }

        return $this->hasChangedDescendant($nodeJson);
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
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode JSON string.');
        }

        return $encoded;
    }
}
