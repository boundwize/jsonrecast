<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer;

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
use RuntimeException;

use function count;
use function is_string;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class JsonPrettyPrinter implements JsonPrinter
{
    public function __construct(
        private string $indent = '    ',
        private int $maximumDepth = MaximumDepthGuard::DEFAULT_MAXIMUM_DEPTH,
    ) {
        MaximumDepthGuard::validateMaximumDepth($maximumDepth);
    }

    public function print(NodeJson $nodeJson): string
    {
        return $this->printNode($nodeJson, new PrintContext($this->indent), 0);
    }

    private function printNode(NodeJson $nodeJson, PrintContext $printContext, int $depth): string
    {
        if (
            ! $nodeJson instanceof JsonDocument
            && ! $nodeJson instanceof ObjectItemNode
            && ! $nodeJson instanceof ArrayItemNode
        ) {
            MaximumDepthGuard::guardMaximumDepth($this->maximumDepth, $depth);
        }

        return match (true) {
            $nodeJson instanceof JsonDocument => $this->printNode($nodeJson->value, $printContext, $depth),
            $nodeJson instanceof ObjectNode => $this->printObject($nodeJson, $printContext, $depth),
            $nodeJson instanceof ObjectItemNode => $this->printObjectItem($nodeJson, $printContext, $depth),
            $nodeJson instanceof ArrayNode => $this->printArray($nodeJson, $printContext, $depth),
            $nodeJson instanceof ArrayItemNode => $this->printNode($nodeJson->value, $printContext, $depth),
            $nodeJson instanceof StringNode => $this->encodeString($nodeJson->value),
            $nodeJson instanceof NumberNode => $nodeJson->rawValue,
            $nodeJson instanceof BooleanNode => $nodeJson->value ? 'true' : 'false',
            $nodeJson instanceof NullNode => 'null',
            default => throw new RuntimeException('Unsupported JSON node.'),
        };
    }

    private function printObject(ObjectNode $objectNode, PrintContext $printContext, int $depth): string
    {
        if ($objectNode->items === []) {
            return '{}';
        }

        $output = '{';

        foreach ($objectNode->items as $i => $item) {
            $output .= $printContext->newline
                . $printContext->childIndentation()
                . $this->printObjectItem($item, $printContext->next(), $depth + 1);

            if ($i < count($objectNode->items) - 1) {
                $output .= ',';
            }
        }

        return $output . $printContext->newline . $printContext->indentation() . '}';
    }

    private function printObjectItem(ObjectItemNode $objectItemNode, PrintContext $printContext, int $depth): string
    {
        return $this->encodeString($objectItemNode->key->value)
            . ': '
            . $this->printNode($objectItemNode->value, $printContext, $depth);
    }

    private function printArray(ArrayNode $arrayNode, PrintContext $printContext, int $depth): string
    {
        if ($arrayNode->items === []) {
            return '[]';
        }

        $output = '[';

        foreach ($arrayNode->items as $i => $item) {
            $output .= $printContext->newline
                . $printContext->childIndentation()
                . $this->printNode($item->value, $printContext->next(), $depth + 1);

            if ($i < count($arrayNode->items) - 1) {
                $output .= ',';
            }
        }

        return $output . $printContext->newline . $printContext->indentation() . ']';
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
