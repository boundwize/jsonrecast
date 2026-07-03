<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast;

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

use function get_debug_type;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;
use function str_repeat;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class AstDumper
{
    public function __construct(
        private string $indent = '  ',
        private bool $includeAttributes = false,
    ) {
    }

    public function dump(NodeJson|JsonRecastResult $input): string
    {
        $nodeJson = $input instanceof JsonRecastResult ? $input->document : $input;

        return implode("\n", $this->dumpNode($nodeJson, 0));
    }

    /**
     * @return list<string>
     */
    private function dumpNode(NodeJson $nodeJson, int $level, ?string $label = null): array
    {
        $lines = [
            $this->line($level, $this->describe($nodeJson), $label),
        ];

        if ($this->includeAttributes) {
            $this->appendNamedValues($lines, 'attributes', $nodeJson->getAttributes(), $level + 1);
        }

        if ($nodeJson instanceof JsonDocument) {
            $this->appendNode($lines, $nodeJson->value, $level + 1, 'value');

            return $lines;
        }

        if ($nodeJson instanceof ObjectNode) {
            $this->appendObjectItems($lines, $nodeJson, $level + 1);

            return $lines;
        }

        if ($nodeJson instanceof ObjectItemNode) {
            $this->appendNode($lines, $nodeJson->key, $level + 1, 'key');
            $this->appendNode($lines, $nodeJson->value, $level + 1, 'value');

            return $lines;
        }

        if ($nodeJson instanceof ArrayNode) {
            $this->appendArrayItems($lines, $nodeJson, $level + 1);

            return $lines;
        }

        if ($nodeJson instanceof ArrayItemNode) {
            $this->appendNode($lines, $nodeJson->value, $level + 1, 'value');
        }

        return $lines;
    }

    private function describe(NodeJson $nodeJson): string
    {
        return match (true) {
            $nodeJson instanceof JsonDocument => 'JsonDocument',
            $nodeJson instanceof ObjectNode => 'ObjectNode',
            $nodeJson instanceof ObjectItemNode => 'ObjectItemNode',
            $nodeJson instanceof ArrayNode => 'ArrayNode',
            $nodeJson instanceof ArrayItemNode => 'ArrayItemNode',
            $nodeJson instanceof StringNode => 'StringNode(value: ' . $this->formatValue($nodeJson->value) . ')',
            $nodeJson instanceof NumberNode => 'NumberNode(rawValue: ' . $this->formatValue($nodeJson->rawValue) . ')',
            $nodeJson instanceof BooleanNode => 'BooleanNode(value: ' . $this->formatValue($nodeJson->value) . ')',
            $nodeJson instanceof NullNode => 'NullNode',
            default => get_debug_type($nodeJson),
        };
    }

    /**
     * @param list<string> $lines
     */
    private function appendObjectItems(array &$lines, ObjectNode $objectNode, int $level): void
    {
        if ($objectNode->items === []) {
            $lines[] = $this->line($level, 'items: []');

            return;
        }

        $lines[] = $this->line($level, 'items:');

        foreach ($objectNode->items as $index => $item) {
            $this->appendNode($lines, $item, $level + 1, '[' . $index . ']');
        }
    }

    /**
     * @param list<string> $lines
     */
    private function appendArrayItems(array &$lines, ArrayNode $arrayNode, int $level): void
    {
        if ($arrayNode->items === []) {
            $lines[] = $this->line($level, 'items: []');

            return;
        }

        $lines[] = $this->line($level, 'items:');

        foreach ($arrayNode->items as $index => $item) {
            $this->appendNode($lines, $item, $level + 1, '[' . $index . ']');
        }
    }

    /**
     * @param list<string> $lines
     */
    private function appendNode(array &$lines, NodeJson $nodeJson, int $level, string $label): void
    {
        foreach ($this->dumpNode($nodeJson, $level, $label) as $line) {
            $lines[] = $line;
        }
    }

    /**
     * @param list<string>        $lines
     * @param array<string, mixed> $values
     */
    private function appendNamedValues(array &$lines, string $name, array $values, int $level): void
    {
        if ($values === []) {
            return;
        }

        $lines[] = $this->line($level, $name . ':');

        foreach ($values as $key => $value) {
            $lines[] = $this->line($level + 1, $key . ': ' . $this->formatValue($value));
        }
    }

    private function line(int $level, string $text, ?string $label = null): string
    {
        if ($label !== null) {
            $text = $label . ': ' . $text;
        }

        return str_repeat($this->indent, $level) . $text;
    }

    private function formatValue(mixed $value): string
    {
        if (is_string($value)) {
            return $this->encode($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return $this->encode($value);
        }

        if (is_object($value)) {
            return 'object(' . $value::class . ')';
        }

        return get_debug_type($value);
    }

    private function encode(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode AST dump value.');
        }

        return $encoded;
    }
}
