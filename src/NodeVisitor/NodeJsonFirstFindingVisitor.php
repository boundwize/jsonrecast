<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Closure;

final class NodeJsonFirstFindingVisitor extends NodeJsonVisitorAbstract
{
    private ?NodeJson $nodeJson = null;

    /**
     * @param Closure(NodeJson, NodeJsonPath): bool $filter
     */
    public function __construct(
        private readonly Closure $filter,
    ) {
    }

    public function beforeTraverse(NodeJson $nodeJson): null
    {
        $this->nodeJson = null;

        return null;
    }

    /**
     * @return NodeJsonVisitor::STOP_TRAVERSAL|null
     */
    public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
    {
        if (($this->filter)($nodeJson, $nodeJsonPath)) {
            $this->nodeJson = $nodeJson;

            return NodeJsonVisitor::STOP_TRAVERSAL;
        }

        return null;
    }

    public function getFoundNode(): ?NodeJson
    {
        return $this->nodeJson;
    }
}
