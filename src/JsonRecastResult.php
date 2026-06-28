<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast;

use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\NodeTraverser\NodeChangeSet;

final readonly class JsonRecastResult
{
    public function __construct(
        public JsonDocument $document,
        public NodeChangeSet $changeSet,
    ) {
    }
}
