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
use Boundwize\JsonRecast\Parser\NumberLexemeScanner;
use RuntimeException;

use function array_splice;
use function count;
use function is_string;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

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
            $nodeJson instanceof StringNode => $this->encodeString($nodeJson->value),
            $nodeJson instanceof NumberNode => $this->encodeNumber($nodeJson->rawValue),
            $nodeJson instanceof BooleanNode => $nodeJson->value ? 'true' : 'false',
            $nodeJson instanceof NullNode => 'null',
            default => throw new RuntimeException('Unsupported JSON node.'),
        };
    }

    private function printObjectItem(ObjectItemNode $objectItemNode, PrintContext $printContext): string
    {
        return $this->encodeString($objectItemNode->key->value)
            . ': '
            . $this->printNode($objectItemNode->value, $printContext);
    }

    private function printCollection(ObjectNode|ArrayNode $node, PrintContext $printContext): string
    {
        array_splice($node->items, 0, 0);

        $isObject       = $node instanceof ObjectNode;
        $openDelimiter  = $isObject ? '{' : '[';
        $closeDelimiter = $isObject ? '}' : ']';

        if ($node->items === []) {
            return $openDelimiter . $closeDelimiter;
        }

        $output    = $openDelimiter;
        $lastIndex = count($node->items) - 1;

        foreach ($node->items as $i => $item) {
            $output .= $printContext->newline
                . $printContext->childIndentation()
                . $this->printNode($item, $printContext->next());

            if ($i < $lastIndex) {
                $output .= ',';
            }
        }

        return $output . $printContext->newline . $printContext->indentation() . $closeDelimiter;
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

    private function encodeNumber(string $rawValue): string
    {
        if (! NumberLexemeScanner::isValidLexeme($rawValue)) {
            throw new RuntimeException('Unable to encode JSON number.');
        }

        return $rawValue;
    }
}
