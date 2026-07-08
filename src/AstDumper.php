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

use function count;
use function get_debug_type;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;
use function max;
use function str_repeat;
use function strlen;

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

        return implode("\n", $this->dumpNode($nodeJson, '', true, null, true));
    }

    /**
     * @return list<string>
     */
    private function dumpNode(
        NodeJson $nodeJson,
        string $prefix,
        bool $isLast,
        ?string $label = null,
        bool $isRoot = false,
    ): array {
        $lines    = [
            $this->line($prefix, $isLast, $this->describe($nodeJson), $label, $isRoot),
        ];
        $children = $this->children($nodeJson);

        if ($children === []) {
            return $lines;
        }

        $childPrefix = $isRoot ? '' : $this->childPrefix($prefix, $isLast);
        $lastIndex   = count($children) - 1;

        foreach ($children as $index => $child) {
            if ($child['kind'] === 'node') {
                $this->appendNode($lines, $child['node'], $childPrefix, $index === $lastIndex, $child['label']);

                continue;
            }

            $this->appendNamedValues(
                $lines,
                $child['label'],
                $child['values'],
                $childPrefix,
                $index === $lastIndex,
            );
        }

        return $lines;
    }

    private function describe(NodeJson $nodeJson): string
    {
        return match (true) {
            $nodeJson instanceof JsonDocument => 'JsonDocument',
            $nodeJson instanceof ObjectNode => $this->describeCounted('ObjectNode', count($nodeJson->items), 'item'),
            $nodeJson instanceof ObjectItemNode => 'ObjectItemNode',
            $nodeJson instanceof ArrayNode => $this->describeCounted('ArrayNode', count($nodeJson->items), 'item'),
            $nodeJson instanceof ArrayItemNode => 'ArrayItemNode',
            $nodeJson instanceof StringNode => 'StringNode(value: ' . $this->formatValue($nodeJson->value) . ')',
            $nodeJson instanceof NumberNode => 'NumberNode(rawValue: ' . $this->formatValue($nodeJson->rawValue) . ')',
            $nodeJson instanceof BooleanNode => 'BooleanNode(value: ' . $this->formatValue($nodeJson->value) . ')',
            $nodeJson instanceof NullNode => 'NullNode',
            default => get_debug_type($nodeJson),
        };
    }

    private function describeCounted(string $name, int $count, string $unit): string
    {
        return $name . ' (' . $count . ' ' . $unit . ($count === 1 ? '' : 's') . ')';
    }

    /**
     * @return list<
     *     array{kind: 'node', label: string, node: NodeJson}
     *     |array{kind: 'values', label: string, values: array<string, mixed>}
     * >
     */
    private function children(NodeJson $nodeJson): array
    {
        $children = [];

        if ($this->includeAttributes && $nodeJson->getAttributes() !== []) {
            $children[] = [
                'kind'   => 'values',
                'label'  => 'attributes',
                'values' => $nodeJson->getAttributes(),
            ];
        }

        if ($nodeJson instanceof JsonDocument) {
            $children[] = [
                'kind'  => 'node',
                'label' => 'value',
                'node'  => $nodeJson->value,
            ];

            return $children;
        }

        if ($nodeJson instanceof ObjectNode || $nodeJson instanceof ArrayNode) {
            foreach ($nodeJson->items as $index => $item) {
                $children[] = [
                    'kind'  => 'node',
                    'label' => '[' . $index . ']',
                    'node'  => $item,
                ];
            }

            return $children;
        }

        if ($nodeJson instanceof ObjectItemNode) {
            $children[] = [
                'kind'  => 'node',
                'label' => 'key',
                'node'  => $nodeJson->key,
            ];
            $children[] = [
                'kind'  => 'node',
                'label' => 'value',
                'node'  => $nodeJson->value,
            ];

            return $children;
        }

        if ($nodeJson instanceof ArrayItemNode) {
            $children[] = [
                'kind'  => 'node',
                'label' => 'value',
                'node'  => $nodeJson->value,
            ];
        }

        return $children;
    }

    /**
     * @param list<string> $lines
     */
    private function appendNode(array &$lines, NodeJson $nodeJson, string $prefix, bool $isLast, string $label): void
    {
        foreach ($this->dumpNode($nodeJson, $prefix, $isLast, $label) as $line) {
            $lines[] = $line;
        }
    }

    /**
     * @param list<string>        $lines
     * @param array<string, mixed> $values
     */
    private function appendNamedValues(
        array &$lines,
        string $name,
        array $values,
        string $prefix,
        bool $isLast,
    ): void {
        $lines[] = $this->line($prefix, $isLast, $name);

        $childPrefix = $this->childPrefix($prefix, $isLast);
        $lastIndex   = count($values) - 1;
        $index       = 0;

        foreach ($values as $key => $value) {
            $lines[] = $this->line(
                $childPrefix,
                $index === $lastIndex,
                $key . ': ' . $this->formatValue($value),
            );
            $index++;
        }
    }

    private function line(
        string $prefix,
        bool $isLast,
        string $text,
        ?string $label = null,
        bool $isRoot = false,
    ): string {
        if ($label !== null) {
            $text = $label . ': ' . $text;
        }

        if ($isRoot) {
            return $text;
        }

        return $prefix . $this->branch($isLast) . $text;
    }

    private function childPrefix(string $prefix, bool $isLast): string
    {
        return $prefix . ($isLast ? ' ' : '│') . str_repeat(' ', $this->branchWidth() - 1);
    }

    private function branch(bool $isLast): string
    {
        return ($isLast ? '└' : '├') . str_repeat('─', $this->branchWidth() - 2) . ' ';
    }

    private function branchWidth(): int
    {
        return max(3, strlen($this->indent) + 2);
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
