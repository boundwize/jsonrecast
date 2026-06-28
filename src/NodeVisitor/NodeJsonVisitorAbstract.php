<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;

abstract class NodeJsonVisitorAbstract implements NodeJsonVisitor
{
    public function beforeTraverse(NodeJson $nodeJson): null|NodeJson|int
    {
        return null;
    }

    public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|NodeJson|int
    {
        return null;
    }

    public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|NodeJson|int
    {
        return null;
    }

    public function afterTraverse(NodeJson $nodeJson): null|NodeJson|int
    {
        return null;
    }
}
