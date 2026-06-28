<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeTraverser;

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

    public function markChanged(NodeJson $nodeJson): void
    {
        $this->changed[$nodeJson] = true;
    }

    public function isChanged(NodeJson $nodeJson): bool
    {
        return isset($this->changed[$nodeJson]);
    }
}
