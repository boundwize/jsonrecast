<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast;

use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\NodeVisitor\NodeChangeSet;

final class JsonRecastResult
{
    public function __construct(
        public readonly JsonDocument $document,
        public readonly NodeChangeSet $changeSet,
    ) {
    }
}
