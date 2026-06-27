<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

use Boundwize\JsonRecast\Node\NodeJson;

interface NodeJsonVisitor
{
    public function beforeTraverse(NodeJson $node): null|NodeJson|NodeJsonRemoval;

    public function enterNode(NodeJson $node, NodeJsonPath $path): null|NodeJson|NodeJsonRemoval;

    public function leaveNode(NodeJson $node, NodeJsonPath $path): null|NodeJson|NodeJsonRemoval;

    public function afterTraverse(NodeJson $node): null|NodeJson|NodeJsonRemoval;
}
