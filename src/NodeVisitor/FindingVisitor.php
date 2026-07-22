<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Closure;

final class FindingVisitor extends NodeJsonVisitorAbstract
{
    /** @var list<NodeJson> */
    private array $foundNodes = [];

    /**
     * @param Closure(NodeJson, NodeJsonPath): bool $filter
     */
    public function __construct(
        private readonly Closure $filter,
    ) {
    }

    public function beforeTraverse(NodeJson $nodeJson): null
    {
        $this->foundNodes = [];

        return null;
    }

    public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null
    {
        if (($this->filter)($nodeJson, $nodeJsonPath)) {
            $this->foundNodes[] = $nodeJson;
        }

        return null;
    }

    /**
     * @return list<NodeJson>
     */
    public function getFoundNodes(): array
    {
        return $this->foundNodes;
    }
}
