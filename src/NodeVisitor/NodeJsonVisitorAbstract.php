<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

use Boundwize\JsonRecast\Node\NodeJson;

abstract class NodeJsonVisitorAbstract implements NodeJsonVisitor
{
    public function beforeTraverse(NodeJson $node): null|NodeJson|NodeJsonRemoval
    {
        return null;
    }

    public function enterNode(NodeJson $node, NodeJsonPath $path): null|NodeJson|NodeJsonRemoval
    {
        return null;
    }

    public function leaveNode(NodeJson $node, NodeJsonPath $path): null|NodeJson|NodeJsonRemoval
    {
        return null;
    }

    public function afterTraverse(NodeJson $node): null|NodeJson|NodeJsonRemoval
    {
        return null;
    }
}
