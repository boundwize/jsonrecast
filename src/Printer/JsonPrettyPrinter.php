<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer;

use Boundwize\JsonRecast\Guard\IndentGuard;
use Boundwize\JsonRecast\Guard\MaximumDepthGuard;
use Boundwize\JsonRecast\Guard\NodeTreeGuard;
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
use Boundwize\JsonRecast\Printer\Helper\ContainerPrintHelper;
use Boundwize\JsonRecast\Printer\Helper\ScalarEncodeHelper;
use RuntimeException;

use function array_splice;

final readonly class JsonPrettyPrinter implements JsonPrinter
{
    private string $indent;

    /** @var positive-int */
    private int $maximumDepth;

    public function __construct(
        string $indent = '    ',
        int $maximumDepth = MaximumDepthGuard::DEFAULT_MAXIMUM_DEPTH,
    ) {
        $this->indent       = IndentGuard::validateIndent($indent);
        $this->maximumDepth = MaximumDepthGuard::validateMaximumDepth($maximumDepth);
    }

    public function print(NodeJson $nodeJson): string
    {
        NodeTreeGuard::guardPrintableRoot($nodeJson);
        NodeTreeGuard::guard($nodeJson, $this->maximumDepth);

        return $this->printNode($nodeJson, new PrintContext($this->indent));
    }

    private function printNode(NodeJson $nodeJson, PrintContext $printContext): string
    {
        return match (true) {
            $nodeJson instanceof JsonDocument => $this->printNode($nodeJson->value, $printContext),
            $nodeJson instanceof ObjectNode, $nodeJson instanceof ArrayNode => $this->printCollection(
                $nodeJson,
                $printContext,
            ),
            $nodeJson instanceof ObjectItemNode => $this->printObjectItem($nodeJson, $printContext),
            $nodeJson instanceof ArrayItemNode => $this->printNode($nodeJson->value, $printContext),
            $nodeJson instanceof StringNode => ScalarEncodeHelper::encodeString(
                $nodeJson->value,
                $this->maximumDepth,
            ),
            $nodeJson instanceof NumberNode => ScalarEncodeHelper::encodeNumber($nodeJson->rawValue),
            $nodeJson instanceof BooleanNode => $nodeJson->value ? 'true' : 'false',
            $nodeJson instanceof NullNode => 'null',
            default => throw new RuntimeException('Unsupported JSON node.'),
        };
    }

    private function printObjectItem(ObjectItemNode $objectItemNode, PrintContext $printContext): string
    {
        return ScalarEncodeHelper::encodeString($objectItemNode->key->value, $this->maximumDepth)
            . ': '
            . $this->printNode($objectItemNode->value, $printContext);
    }

    private function printCollection(ObjectNode|ArrayNode $node, PrintContext $printContext): string
    {
        array_splice($node->items, 0, 0);

        if ($node->items === []) {
            return ContainerPrintHelper::openingDelimiter($node) . ContainerPrintHelper::closingDelimiter($node);
        }

        return ContainerPrintHelper::printItemsOnOwnLines(
            $node,
            $printContext,
            fn (
                ArrayItemNode|ObjectItemNode $item,
                PrintContext $childPrintContext,
            ): string => $this->printNode($item, $childPrintContext),
        );
    }
}
