<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

use Boundwize\JsonRecast\Node\NodeJson;
use SplObjectStorage;

final class NodeChangeSet
{
    /** @var SplObjectStorage<NodeJson, true> */
    private SplObjectStorage $changed;

    public function __construct()
    {
        $this->changed = new SplObjectStorage();
    }

    public function markChanged(NodeJson $node): void
    {
        $this->changed[$node] = true;
    }

    public function isChanged(NodeJson $node): bool
    {
        return isset($this->changed[$node]);
    }
}
