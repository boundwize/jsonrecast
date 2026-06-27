<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer;

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
use RuntimeException;

use function count;
use function is_string;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;

final class JsonPrettyPrinter implements JsonPrinter
{
    public function __construct(
        private readonly string $indent = '    ',
    ) {
    }

    public function print(NodeJson $node): string
    {
        return $this->printNode($node, new PrintContext($this->indent));
    }

    private function printNode(NodeJson $node, PrintContext $context): string
    {
        return match (true) {
            $node instanceof JsonDocument => $this->printNode($node->value, $context),
            $node instanceof ObjectNode => $this->printObject($node, $context),
            $node instanceof ObjectItemNode => $this->printObjectItem($node, $context),
            $node instanceof ArrayNode => $this->printArray($node, $context),
            $node instanceof ArrayItemNode => $this->printNode($node->value, $context),
            $node instanceof StringNode => $this->encodeString($node->value),
            $node instanceof NumberNode => $node->rawValue,
            $node instanceof BooleanNode => $node->value ? 'true' : 'false',
            $node instanceof NullNode => 'null',
            default => throw new RuntimeException('Unsupported JSON node.'),
        };
    }

    private function printObject(ObjectNode $node, PrintContext $context): string
    {
        if ($node->items === []) {
            return '{}';
        }

        $output = '{';

        foreach ($node->items as $i => $item) {
            $output .= $context->newline
                . $context->childIndentation()
                . $this->printObjectItem($item, $context->next());

            if ($i < count($node->items) - 1) {
                $output .= ',';
            }
        }

        return $output . $context->newline . $context->indentation() . '}';
    }

    private function printObjectItem(ObjectItemNode $node, PrintContext $context): string
    {
        return $this->encodeString($node->key->value)
            . ': '
            . $this->printNode($node->value, $context);
    }

    private function printArray(ArrayNode $node, PrintContext $context): string
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

    private function encodeString(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode JSON string.');
        }

        return $encoded;
    }
}
