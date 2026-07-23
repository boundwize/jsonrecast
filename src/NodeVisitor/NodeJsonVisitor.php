<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;

/**
 * @phpstan-type NodeJsonVisitorResult null|NodeJson|self::REMOVE_NODE|self::STOP_TRAVERSAL
 */
interface NodeJsonVisitor
{
    public const REMOVE_NODE = 1;

    /**
     * Only valid from enterNode() and leaveNode(): stop visiting further nodes,
     * skip the remaining visitors, keep the current root, still call afterTraverse().
     */
    public const STOP_TRAVERSAL = 2;

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
