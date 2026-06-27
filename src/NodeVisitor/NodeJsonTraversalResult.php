<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

use Boundwize\JsonRecast\Node\NodeJson;

final class NodeJsonTraversalResult
{
    public function __construct(
        public readonly NodeJson $node,
        public readonly NodeChangeSet $changeSet,
    ) {
    }
}
