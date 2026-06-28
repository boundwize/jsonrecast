<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

use Boundwize\JsonRecast\Node\NodeJson;

/**
 * @phpstan-type NodeJsonVisitorResult null|NodeJson|self::REMOVE_NODE
 */
interface NodeJsonVisitor
{
    public const REMOVE_NODE = 1;

    /**
     * @return NodeJsonVisitorResult
     */
    public function beforeTraverse(NodeJson $nodeJson): null|NodeJson|int;

    /**
     * @return NodeJsonVisitorResult
     */
    public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|NodeJson|int;

    /**
     * @return NodeJsonVisitorResult
     */
    public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|NodeJson|int;

    /**
     * @return NodeJsonVisitorResult
     */
    public function afterTraverse(NodeJson $nodeJson): null|NodeJson|int;
}
