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
     * nesting exceeds the maximum depth, cyclic trees, and containers holding
     * the wrong item kind, which printers would render as invalid JSON. Wrapper
     * nodes (documents and items) re-enter their value at the same depth, so a
     * cycle through them would never trip the depth guard alone.
     *
     * @param positive-int $maximumDepth
     */
    public static function guard(NodeJson $nodeJson, int $maximumDepth): void
    {
        /** @var list<array{NodeJson, int, bool}> $stack */
        $stack = [[$nodeJson, 0, false]];

        // Tracking only the active path — entered on the way down, released by
        // the leave frame — rejects cycles while still allowing a node shared
        // between siblings.
        /** @var SplObjectStorage<NodeJson, null> $activePathNodes */
        $activePathNodes = new SplObjectStorage();

        while ($stack !== []) {
            /** @var array{NodeJson, int, bool} $entry */
            $entry = array_pop($stack);

            [$currentNode, $depth, $leaving] = $entry;

            if ($leaving) {
                $activePathNodes->offsetUnset($currentNode);
                continue;
            }

            if ($activePathNodes->offsetExists($currentNode)) {
                throw new RuntimeException(self::CYCLIC_MESSAGE);
            }

            if ($currentNode instanceof JsonDocument) {
                $activePathNodes->offsetSet($currentNode);
                $stack[] = [$currentNode, $depth, true];
                $stack[] = [$currentNode->value, $depth, false];
                continue;
            }

            if ($currentNode instanceof ObjectItemNode) {
                $activePathNodes->offsetSet($currentNode);
                $stack[] = [$currentNode, $depth, true];
                $stack[] = [$currentNode->key, $depth, false];
                $stack[] = [$currentNode->value, $depth, false];
                continue;
            }

            if ($currentNode instanceof ObjectNode || $currentNode instanceof ArrayNode) {
                // Match the parser's json_decode()-compatible boundary so every
                // printable tree can be parsed again at the same maximum depth.
                MaximumDepthGuard::guardMaximumDepth($maximumDepth, $depth + 1);

                $activePathNodes->offsetSet($currentNode);
                $stack[] = [$currentNode, $depth, true];

                foreach ($currentNode->items as $item) {
                    if ($currentNode instanceof ObjectNode && ! $item instanceof ObjectItemNode) {
                        throw new RuntimeException('ObjectNode children must be ObjectItemNode.');
                    }

                    if ($currentNode instanceof ArrayNode && ! $item instanceof ArrayItemNode) {
                        throw new RuntimeException('ArrayNode children must be ArrayItemNode.');
                    }

                    $stack[] = [$item, $depth + 1, false];
                }

                continue;
            }

            if ($currentNode instanceof ArrayItemNode) {
                $activePathNodes->offsetSet($currentNode);
                $stack[] = [$currentNode, $depth, true];
                $stack[] = [$currentNode->value, $depth, false];
            }
        }
    }
}
