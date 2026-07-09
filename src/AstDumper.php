<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast;

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

use function array_pop;
use function count;
use function explode;
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
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function strlen;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class AstDumper
{
    /** @var positive-int */
    private int $maximumDepth;

    public function __construct(
        private string $indent = '  ',
        private bool $includeAttributes = false,
        int $maximumDepth = MaximumDepthGuard::DEFAULT_MAXIMUM_DEPTH,
    ) {
        $this->maximumDepth = MaximumDepthGuard::validateMaximumDepth($maximumDepth);
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
        int $depth = 0,
    ): array {
        if (
            ! $nodeJson instanceof JsonDocument
            && ! $nodeJson instanceof ObjectItemNode
            && ! $nodeJson instanceof ArrayItemNode
        ) {
            MaximumDepthGuard::guardMaximumDepth($this->maximumDepth, $depth);
        }

        $lines    = [
            $this->line($prefix, $isLast, $this->describe($nodeJson), $label, $isRoot),
        ];
        $children = $this->children($nodeJson);

        if ($children === []) {
            return $lines;
        }

        $childPrefix = $isRoot ? '' : $this->childPrefix($prefix, $isLast);
        $lastIndex   = count($children) - 1;
        $childDepth  = $nodeJson instanceof ObjectNode || $nodeJson instanceof ArrayNode
            ? $depth + 1
            : $depth;

        foreach ($children as $index => $child) {
            if ($child['kind'] === 'node') {
                $this->appendNode(
                    $lines,
                    $child['node'],
                    $childPrefix,
                    $index === $lastIndex,
                    $child['label'],
                    $childDepth,
                );

                continue;
            }

            if ($child['kind'] === 'values') {
                $this->appendNamedValues(
                    $lines,
                    $child['label'],
                    $child['values'],
                    $childPrefix,
                    $index === $lastIndex,
                );

                continue;
            }

            $this->appendNodeGroup(
                $lines,
                $child['label'],
                $child['nodes'],
                $childPrefix,
                $index === $lastIndex,
                $childDepth,
            );
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

    private function describeCounted(string $name, int $count, string $unit): string
    {
        return $name . ' (' . $count . ' ' . $unit . ($count === 1 ? '' : 's') . ')';
    }

    /**
     * @return list<
     *     array{kind: 'node', label: string, node: NodeJson}
     *     |array{kind: 'values', label: string, values: array<string, mixed>}
     *     |array{kind: 'nodes', label: string, nodes: list<NodeJson>}
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
            $children[] = [
                'kind'  => 'nodes',
                'label' => $this->describeCounted('items', count($nodeJson->items), 'item'),
                'nodes' => $nodeJson->items,
            ];

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
    private function appendNode(
        array &$lines,
        NodeJson $nodeJson,
        string $prefix,
        bool $isLast,
        string $label,
        int $depth,
    ): void {
        foreach ($this->dumpNode($nodeJson, $prefix, $isLast, $label, depth: $depth) as $line) {
            $lines[] = $line;
        }
    }

    /**
     * @param list<string>   $lines
     * @param list<NodeJson> $nodes
     */
    private function appendNodeGroup(
        array &$lines,
        string $name,
        array $nodes,
        string $prefix,
        bool $isLast,
        int $depth,
    ): void {
        $lines[] = $this->line($prefix, $isLast, $name);

        $childPrefix = $this->childPrefix($prefix, $isLast);
        $lastIndex   = count($nodes) - 1;

        foreach ($nodes as $index => $node) {
            $this->appendNode($lines, $node, $childPrefix, $index === $lastIndex, '[' . $index . ']', $depth);
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
            if (is_string($value) && $this->shouldFormatAsBlockString($value)) {
                $this->appendBlockString($lines, $key, $value, $childPrefix, $index === $lastIndex);
                $index++;

                continue;
            }

            $lines[] = $this->line(
                $childPrefix,
                $index === $lastIndex,
                $key . ': ' . $this->formatNamedValue($key, $value),
            );
            $index++;
        }
    }

    /**
     * @param list<string> $lines
     */
    private function appendBlockString(
        array &$lines,
        string $key,
        string $value,
        string $prefix,
        bool $isLast,
    ): void {
        $normalizedValue = str_replace(["\r\n", "\r"], "\n", $value);
        $endsWithNewline = str_ends_with($normalizedValue, "\n");
        $blockLines      = explode("\n", $normalizedValue);

        if ($endsWithNewline) {
            array_pop($blockLines);
        }

        $lines[]       = $this->line($prefix, $isLast, $key . ': ' . ($endsWithNewline ? '|' : '|-'));
        $contentPrefix = $this->childPrefix($prefix, $isLast);

        foreach ($blockLines as $blockLine) {
            $lines[] = $blockLine === '' ? rtrim($contentPrefix) : $contentPrefix . $blockLine;
        }
    }

    private function shouldFormatAsBlockString(string $value): bool
    {
        return strlen($value) > 1 && (str_contains($value, "\n") || str_contains($value, "\r"));
    }

    private function formatNamedValue(string $key, mixed $value): string
    {
        if (is_string($value) && $this->shouldFormatAsSourceText($key)) {
            return $value;
        }

        return $this->formatValue($value);
    }

    private function shouldFormatAsSourceText(string $key): bool
    {
        return $key === 'originalText' || $key === 'source';
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
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            $this->maximumDepth,
        );

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode AST dump value.');
        }

        return $encoded;
    }
}
