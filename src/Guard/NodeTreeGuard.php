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
     * nesting exceeds the maximum depth, and cyclic trees. Wrapper nodes
     * (documents and items) re-enter their value at the same depth, so a cycle
     * through them would never trip the depth guard alone.
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
                $activePathNodes->detach($currentNode);
                continue;
            }

            if ($activePathNodes->contains($currentNode)) {
                throw new RuntimeException(self::CYCLIC_MESSAGE);
            }

            // json_encode() only consumes a nesting level when entering a container,
            // so scalar leaves at the final allowed depth are printable
            if ($currentNode instanceof ObjectNode || $currentNode instanceof ArrayNode) {
                MaximumDepthGuard::guardMaximumDepth($maximumDepth, $depth);
            }

            if ($currentNode instanceof JsonDocument) {
                $activePathNodes->attach($currentNode);
                $stack[] = [$currentNode, $depth, true];
                $stack[] = [$currentNode->value, $depth, false];
                continue;
            }

            if ($currentNode instanceof ObjectItemNode) {
                $activePathNodes->attach($currentNode);
                $stack[] = [$currentNode, $depth, true];
                $stack[] = [$currentNode->key, $depth, false];
                $stack[] = [$currentNode->value, $depth, false];
                continue;
            }

            if ($currentNode instanceof ObjectNode || $currentNode instanceof ArrayNode) {
                $activePathNodes->attach($currentNode);
                $stack[] = [$currentNode, $depth, true];

                foreach ($currentNode->items as $item) {
                    $stack[] = [$item, $depth + 1, false];
                }

                continue;
            }

            if ($currentNode instanceof ArrayItemNode) {
                $activePathNodes->attach($currentNode);
                $stack[] = [$currentNode, $depth, true];
                $stack[] = [$currentNode->value, $depth, false];
            }
        }
    }
}
