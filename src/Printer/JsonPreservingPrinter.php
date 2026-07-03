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
use function json_decode;
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
            $jsonDocument->afterValue === ''
            && $jsonDocument->getAttribute(NodeAttributes::TRAILING_NEWLINE) === true
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

        foreach ($objectNode->items as $i => $item) {
            $output .= $this->printObjectItemPreserving(
                $item,
                $printContext->next(),
                $i === 0 ? $objectNode->afterOpenBrace : null,
                $i === $lastIndex ? $objectNode->beforeCloseBrace : null,
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

        foreach ($arrayNode->items as $i => $item) {
            $output .= $this->printArrayItemPreserving(
                $item,
                $printContext->next(),
                $i === 0 ? $arrayNode->afterOpenBracket : null,
                $i === $lastIndex ? $arrayNode->beforeCloseBracket : null,
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

        if (! is_string($nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT))) {
            return true;
        }

        return $this->hasScalarValueChanged($nodeJson)
            || $this->hasChangedDescendant($nodeJson);
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
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode JSON string.');
        }

        return $encoded;
    }
}
