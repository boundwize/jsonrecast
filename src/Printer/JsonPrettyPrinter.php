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
    /** @var positive-int */
    private int $maximumDepth;

    public function __construct(
        private string $indent = '    ',
        int $maximumDepth = MaximumDepthGuard::DEFAULT_MAXIMUM_DEPTH,
    ) {
        $this->maximumDepth = MaximumDepthGuard::validateMaximumDepth($maximumDepth);
    }

    public function print(NodeJson $nodeJson): string
    {
        return $this->printNode($nodeJson, new PrintContext($this->indent), 0);
    }

    private function printNode(NodeJson $nodeJson, PrintContext $printContext, int $depth): string
    {
        // json_encode() only consumes a nesting level when entering a container,
        // so scalar leaves at the final allowed depth are printable
        if ($nodeJson instanceof ObjectNode || $nodeJson instanceof ArrayNode) {
            MaximumDepthGuard::guardMaximumDepth($this->maximumDepth, $depth);
        }

        return match (true) {
            $nodeJson instanceof JsonDocument => $this->printNode($nodeJson->value, $printContext, $depth),
            $nodeJson instanceof ObjectNode, $nodeJson instanceof ArrayNode => $this->printCollection(
                $nodeJson,
                $printContext,
                $depth,
            ),
            $nodeJson instanceof ObjectItemNode => $this->printObjectItem($nodeJson, $printContext, $depth),
            $nodeJson instanceof ArrayItemNode => $this->printNode($nodeJson->value, $printContext, $depth),
            $nodeJson instanceof StringNode => $this->encodeString($nodeJson->value),
            $nodeJson instanceof NumberNode => $nodeJson->rawValue,
            $nodeJson instanceof BooleanNode => $nodeJson->value ? 'true' : 'false',
            $nodeJson instanceof NullNode => 'null',
            default => throw new RuntimeException('Unsupported JSON node.'),
        };
    }

    private function printObjectItem(ObjectItemNode $objectItemNode, PrintContext $printContext, int $depth): string
    {
        return $this->encodeString($objectItemNode->key->value)
            . ': '
            . $this->printNode($objectItemNode->value, $printContext, $depth);
    }

    private function printCollection(ObjectNode|ArrayNode $node, PrintContext $printContext, int $depth): string
    {
        $isObject       = $node instanceof ObjectNode;
        $openDelimiter  = $isObject ? '{' : '[';
        $closeDelimiter = $isObject ? '}' : ']';

        if ($node->items === []) {
            return $openDelimiter . $closeDelimiter;
        }

        $output = $openDelimiter;

        foreach ($node->items as $i => $item) {
            $output .= $printContext->newline
                . $printContext->childIndentation()
                . $this->printCollectionItem($item, $printContext->next(), $depth + 1);

            if ($i < count($node->items) - 1) {
                $output .= ',';
            }
        }

        return $output . $printContext->newline . $printContext->indentation() . $closeDelimiter;
    }

    private function printCollectionItem(
        ObjectItemNode|ArrayItemNode $item,
        PrintContext $printContext,
        int $depth,
    ): string {
        return match (true) {
            $item instanceof ObjectItemNode => $this->printObjectItem($item, $printContext, $depth),
            $item instanceof ArrayItemNode => $this->printNode($item->value, $printContext, $depth),
        };
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
