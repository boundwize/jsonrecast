<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Guard;

use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use RuntimeException;
use SplObjectStorage;

use function array_pop;

final class NodeTreeGuard
{
    public const CYCLIC_MESSAGE = 'Cyclic JSON AST detected.';

    private function __construct()
    {
    }

    /**
     * Rejects node trees that cannot be traversed safely: trees whose container
     * nesting exceeds the maximum depth, cyclic trees, containers holding the
     * wrong item kind, and wrapper nodes (documents and items) placed in a JSON
     * value position — all shapes printers would render as invalid JSON. Wrapper
     * nodes re-enter their value at the same depth, so a cycle through them
     * would never trip the depth guard alone.
     *
     * @param positive-int $maximumDepth
     */
    public static function guard(NodeJson $nodeJson, int $maximumDepth): void
    {
        /** @var list<array{NodeJson, int, bool, bool}> $stack */
        $stack = [[$nodeJson, 0, false, false]];

        // Tracking only the active path — entered on the way down, released by
        // the leave frame — rejects cycles while still allowing a node shared
        // between siblings.
        /** @var SplObjectStorage<NodeJson, null> $activePathNodes */
        $activePathNodes = new SplObjectStorage();

        while ($stack !== []) {
            /** @var array{NodeJson, int, bool, bool} $entry */
            $entry = array_pop($stack);

            [$currentNode, $depth, $leaving, $isInValuePosition] = $entry;

            if ($leaving) {
                $activePathNodes->offsetUnset($currentNode);
                continue;
            }

            if ($activePathNodes->offsetExists($currentNode)) {
                throw new RuntimeException(self::CYCLIC_MESSAGE);
            }

            if ($currentNode instanceof JsonDocument) {
                if ($isInValuePosition) {
                    throw new RuntimeException('JsonDocument cannot be used as a JSON value.');
                }

                $activePathNodes->offsetSet($currentNode);
                $stack[] = [$currentNode, $depth, true, false];
                $stack[] = [$currentNode->value, $depth, false, true];
                continue;
            }

            if ($currentNode instanceof ObjectItemNode) {
                if ($isInValuePosition) {
                    throw new RuntimeException('ObjectItemNode cannot be used as a JSON value.');
                }

                $activePathNodes->offsetSet($currentNode);
                $stack[] = [$currentNode, $depth, true, false];
                $stack[] = [$currentNode->key, $depth, false, false];
                $stack[] = [$currentNode->value, $depth, false, true];
                continue;
            }

            if ($currentNode instanceof ObjectNode || $currentNode instanceof ArrayNode) {
                // Match the parser's json_decode()-compatible boundary so every
                // printable tree can be parsed again at the same maximum depth.
                MaximumDepthGuard::guardMaximumDepth($maximumDepth, $depth + 1);

                $activePathNodes->offsetSet($currentNode);
                $stack[] = [$currentNode, $depth, true, false];

                foreach ($currentNode->items as $item) {
                    self::guardContainerItemKind($currentNode, $item);

                    $stack[] = [$item, $depth + 1, false, false];
                }

                continue;
            }

            if ($currentNode instanceof ArrayItemNode) {
                if ($isInValuePosition) {
                    throw new RuntimeException('ArrayItemNode cannot be used as a JSON value.');
                }

                $activePathNodes->offsetSet($currentNode);
                $stack[] = [$currentNode, $depth, true, false];
                $stack[] = [$currentNode->value, $depth, false, true];
            }
        }
    }

    /**
     * The declared item types hold by construction; this re-checks them because
     * the items property is public and accepts any node array at runtime.
     */
    private static function guardContainerItemKind(ObjectNode|ArrayNode $containerNode, NodeJson $nodeJson): void
    {
        if ($containerNode instanceof ObjectNode && ! $nodeJson instanceof ObjectItemNode) {
            throw new RuntimeException('ObjectNode children must be ObjectItemNode.');
        }

        if ($containerNode instanceof ArrayNode && ! $nodeJson instanceof ArrayItemNode) {
            throw new RuntimeException('ArrayNode children must be ArrayItemNode.');
        }
    }
}
