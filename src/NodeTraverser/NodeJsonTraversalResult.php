<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeTraverser;

use Boundwize\JsonRecast\Node\NodeJson;

final readonly class NodeJsonTraversalResult
{
    public function __construct(
        public NodeJson $node,
        public NodeChangeSet $changeSet,
    ) {
    }
}
