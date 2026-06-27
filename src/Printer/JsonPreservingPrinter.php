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
use Boundwize\JsonRecast\NodeVisitor\NodeChangeSet;
use RuntimeException;

use function count;
use function is_string;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;

final class JsonPreservingPrinter implements JsonPrinter
{
    public function __construct(
        private readonly ?NodeChangeSet $changeSet = null,
        private readonly string $indent = '    ',
    ) {
    }

    public function print(NodeJson $node): string
    {
        $newline = $node instanceof JsonDocument && is_string($node->getAttribute(NodeAttributes::NEWLINE))
            ? $node->getAttribute(NodeAttributes::NEWLINE)
            : "\n";

        return $this->printNode($node, new PrintContext($this->indent, $newline));
    }

    private function printNode(NodeJson $node, PrintContext $context): string
    {
        if (! $this->isChanged($node)) {
            $originalText = $node->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (is_string($originalText)) {
                return $originalText;
            }
        }

        return match (true) {
            $node instanceof JsonDocument => $this->printDocument($node, $context),
            $node instanceof ObjectNode => $this->printObject($node, $context),
            $node instanceof ObjectItemNode => $this->printObjectItemPreserving($node, $context),
            $node instanceof ArrayNode => $this->printArray($node, $context),
            $node instanceof ArrayItemNode => $this->printArrayItemPreserving($node, $context),
            $node instanceof StringNode => $this->encodeString($node->value),
            $node instanceof NumberNode => $node->rawValue,
            $node instanceof BooleanNode => $node->value ? 'true' : 'false',
            $node instanceof NullNode => 'null',
            default => throw new RuntimeException('Unsupported JSON node.'),
        };
    }

    private function printDocument(JsonDocument $node, PrintContext $context): string
    {
        $output = $this->printNode($node->value, $context);

        if ($node->getAttribute(NodeAttributes::TRAILING_NEWLINE) === true) {
            $output .= $context->newline;
        }

        return $output;
    }

    private function printObject(ObjectNode $node, PrintContext $context): string
    {
        if ($this->shouldPrintContainerBestEffort($node, $node->items)) {
            return $this->printObjectBestEffort($node, $context);
        }

        $output = '{';

        foreach ($node->items as $i => $item) {
            $output .= $this->printObjectItemPreserving($item, $context->next());

            if ($i < count($node->items) - 1) {
                $output .= ',';
            }
        }

        return $output . '}';
    }

    private function printObjectBestEffort(ObjectNode $node, PrintContext $context): string
    {
        if ($node->items === []) {
            return '{}';
        }

        $output = '{';

        foreach ($node->items as $i => $item) {
            $output .= $context->newline
                . $context->childIndentation()
                . $this->printObjectItemBestEffort($item, $context->next());

            if ($i < count($node->items) - 1) {
                $output .= ',';
            }
        }

        return $output . $context->newline . $context->indentation() . '}';
    }

    private function printObjectItemPreserving(ObjectItemNode $node, PrintContext $context): string
    {
        if (! $this->isChanged($node)) {
            $originalText = $node->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (is_string($originalText)) {
                return $originalText;
            }
        }

        return $node->beforeKey
            . $this->printNode($node->key, $context)
            . $node->betweenKeyAndColon
            . ':'
            . $node->betweenColonAndValue
            . $this->printNode($node->value, $context)
            . $node->afterValue;
    }

    private function printObjectItemBestEffort(ObjectItemNode $node, PrintContext $context): string
    {
        return $this->printNode($node->key, $context)
            . ': '
            . $this->printNode($node->value, $context);
    }

    private function printArray(ArrayNode $node, PrintContext $context): string
    {
        if ($this->shouldPrintContainerBestEffort($node, $node->items)) {
            return $this->printArrayBestEffort($node, $context);
        }

        $output = '[';

        foreach ($node->items as $i => $item) {
            $output .= $this->printArrayItemPreserving($item, $context->next());

            if ($i < count($node->items) - 1) {
                $output .= ',';
            }
        }

        return $output . ']';
    }

    private function printArrayBestEffort(ArrayNode $node, PrintContext $context): string
    {
        if ($node->items === []) {
            return '[]';
        }

        $output = '[';

        foreach ($node->items as $i => $item) {
            $output .= $context->newline
                . $context->childIndentation()
                . $this->printNode($item->value, $context->next());

            if ($i < count($node->items) - 1) {
                $output .= ',';
            }
        }

        return $output . $context->newline . $context->indentation() . ']';
    }

    private function printArrayItemPreserving(ArrayItemNode $node, PrintContext $context): string
    {
        if (! $this->isChanged($node)) {
            $originalText = $node->getAttribute(NodeAttributes::ORIGINAL_TEXT);

            if (is_string($originalText)) {
                return $originalText;
            }
        }

        return $node->beforeValue
            . $this->printNode($node->value, $context)
            . $node->afterValue;
    }

    /**
     * @param list<NodeJson> $items
     */
    private function shouldPrintContainerBestEffort(NodeJson $node, array $items): bool
    {
        if ($this->changeSet !== null && $this->changeSet->isChanged($node)) {
            return true;
        }

        foreach ($items as $item) {
            if (! $item->hasAttribute(NodeAttributes::ORIGINAL_TEXT)) {
                return true;
            }
        }

        return ! $node->hasAttribute(NodeAttributes::ORIGINAL_TEXT);
    }

    private function isChanged(NodeJson $node): bool
    {
        if ($this->changeSet !== null && $this->changeSet->isChanged($node)) {
            return true;
        }

        if (! $node->hasAttribute(NodeAttributes::ORIGINAL_TEXT)) {
            return true;
        }

        return $this->hasChangedDescendant($node);
    }

    private function hasChangedDescendant(NodeJson $node): bool
    {
        if ($node instanceof JsonDocument) {
            return $this->isChanged($node->value);
        }

        if ($node instanceof ObjectNode) {
            foreach ($node->items as $item) {
                if ($this->isChanged($item)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof ObjectItemNode) {
            return $this->isChanged($node->key) || $this->isChanged($node->value);
        }

        if ($node instanceof ArrayNode) {
            foreach ($node->items as $item) {
                if ($this->isChanged($item)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof ArrayItemNode) {
            return $this->isChanged($node->value);
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
