<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast;

use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeTraverser\NodeJsonTraverser;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonFindingVisitor;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonFirstFindingVisitor;

/**
 * Read-only query helper. Traversal order and visited nodes are identical to
 * NodeJsonTraverser; use a visitor when nodes must be modified so changes are
 * tracked by the NodeChangeSet.
 */
final class NodeJsonFinder
{
    /**
     * @param callable(NodeJson, NodeJsonPath): bool $filter
     * @return list<NodeJson>
     */
    public function find(NodeJson $nodeJson, callable $filter): array
    {
        $nodeJsonFindingVisitor = new NodeJsonFindingVisitor($filter(...));

        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($nodeJsonFindingVisitor);
        $nodeJsonTraverser->traverse($nodeJson);

        return $nodeJsonFindingVisitor->getFoundNodes();
    }

    /**
     * @param callable(NodeJson, NodeJsonPath): bool $filter
     */
    public function findFirst(NodeJson $nodeJson, callable $filter): ?NodeJson
    {
        $nodeJsonFirstFindingVisitor = new NodeJsonFirstFindingVisitor($filter(...));

        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($nodeJsonFirstFindingVisitor);
        $nodeJsonTraverser->traverse($nodeJson);

        return $nodeJsonFirstFindingVisitor->getFoundNode();
    }

    /**
     * @template TNodeJson of NodeJson
     * @param class-string<TNodeJson> $class
     * @return list<TNodeJson>
     */
    public function findInstanceOf(NodeJson $nodeJson, string $class): array
    {
        /** @var list<TNodeJson> $foundNodes */
        $foundNodes = $this->find(
            $nodeJson,
            static fn (NodeJson $currentNodeJson): bool => $currentNodeJson instanceof $class,
        );

        return $foundNodes;
    }

    /**
     * @template TNodeJson of NodeJson
     * @param class-string<TNodeJson> $class
     * @return TNodeJson|null
     */
    public function findFirstInstanceOf(NodeJson $nodeJson, string $class): ?NodeJson
    {
        /** @var TNodeJson|null $foundNode */
        $foundNode = $this->findFirst(
            $nodeJson,
            static fn (NodeJson $currentNodeJson): bool => $currentNodeJson instanceof $class,
        );

        return $foundNode;
    }
}
