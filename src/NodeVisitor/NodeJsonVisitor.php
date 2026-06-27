<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

use Boundwize\JsonRecast\Node\NodeJson;

interface NodeJsonVisitor
{
    public function beforeTraverse(NodeJson $nodeJson): null|NodeJson|NodeJsonRemoval;

    public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|NodeJson|NodeJsonRemoval;

    public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|NodeJson|NodeJsonRemoval;

    public function afterTraverse(NodeJson $nodeJson): null|NodeJson|NodeJsonRemoval;
}
