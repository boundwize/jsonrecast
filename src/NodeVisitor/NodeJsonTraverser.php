<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use LogicException;

use function array_splice;
use function count;

final class NodeJsonTraverser
{
    /** @var list<NodeJsonVisitor> */
    private array $visitors = [];

    private NodeChangeSet $changeSet;

    public function __construct()
    {
        $this->changeSet = new NodeChangeSet();
    }

    public function addVisitor(NodeJsonVisitor $visitor): void
    {
        $this->visitors[] = $visitor;
    }

    public function traverse(NodeJson $node): NodeJsonTraversalResult
    {
        $this->changeSet = new NodeChangeSet();

        foreach ($this->visitors as $visitor) {
            $result = $visitor->beforeTraverse($node);

            if ($result instanceof NodeJsonRemoval) {
                throw new LogicException('Cannot remove root node during beforeTraverse().');
            }

            if ($result instanceof NodeJson) {
                $this->changeSet->markChanged($result);
                $node = $result;
            }
        }

        $node = $this->traverseNode($node, new NodeJsonPath());

        if ($node instanceof NodeJsonRemoval) {
            throw new LogicException('Cannot remove root node.');
        }

        foreach ($this->visitors as $visitor) {
            $result = $visitor->afterTraverse($node);

            if ($result instanceof NodeJsonRemoval) {
                throw new LogicException('Cannot remove root node during afterTraverse().');
            }

            if ($result instanceof NodeJson) {
                $this->changeSet->markChanged($result);
                $node = $result;
            }
        }

        return new NodeJsonTraversalResult($node, $this->changeSet);
    }

    private function traverseNode(NodeJson $node, NodeJsonPath $path): NodeJson|NodeJsonRemoval
    {
        foreach ($this->visitors as $visitor) {
            $result = $visitor->enterNode($node, $path);

            if ($result instanceof NodeJsonRemoval) {
                return $result;
            }

            if ($result instanceof NodeJson) {
                $this->changeSet->markChanged($result);
                $node = $result;
            }
        }

        if ($node instanceof JsonDocument) {
            $this->traverseDocument($node, $path);
        } elseif ($node instanceof ObjectNode) {
            $this->traverseObject($node, $path);
        } elseif ($node instanceof ObjectItemNode) {
            $this->traverseObjectItem($node, $path);
        } elseif ($node instanceof ArrayNode) {
            $this->traverseArray($node, $path);
        } elseif ($node instanceof ArrayItemNode) {
            $this->traverseArrayItem($node, $path);
        }

        foreach ($this->visitors as $visitor) {
            $result = $visitor->leaveNode($node, $path);

            if ($result instanceof NodeJsonRemoval) {
                return $result;
            }

            if ($result instanceof NodeJson) {
                $this->changeSet->markChanged($result);
                $node = $result;
            }
        }

        return $node;
    }

    private function traverseDocument(JsonDocument $node, NodeJsonPath $path): void
    {
        $result = $this->traverseNode($node->value, $path);

        if ($result instanceof NodeJsonRemoval) {
            throw new LogicException('Cannot remove document value directly.');
        }

        $node->value = $result;
    }

    private function traverseObject(ObjectNode $node, NodeJsonPath $path): void
    {
        $i = 0;

        while ($i < count($node->items)) {
            $result = $this->traverseNode($node->items[$i], $path);

            if ($result instanceof NodeJsonRemoval) {
                array_splice($node->items, $i, 1);
                $this->changeSet->markChanged($node);
                continue;
            }

            if (! $result instanceof ObjectItemNode) {
                throw new LogicException('ObjectNode children must be ObjectItemNode.');
            }

            $node->items[$i] = $result;
            $i++;
        }
    }

    private function traverseObjectItem(ObjectItemNode $node, NodeJsonPath $path): void
    {
        $keyResult = $this->traverseNode($node->key, $path);

        if ($keyResult instanceof NodeJsonRemoval) {
            throw new LogicException('Cannot remove object key directly.');
        }

        if (! $keyResult instanceof StringNode) {
            throw new LogicException('Object item key must be StringNode.');
        }

        $node->key = $keyResult;

        $valuePath   = $path->childObjectKey($node->key->value);
        $valueResult = $this->traverseNode($node->value, $valuePath);

        if ($valueResult instanceof NodeJsonRemoval) {
            throw new LogicException('Cannot remove object value directly. Remove the ObjectItemNode instead.');
        }

        $node->value = $valueResult;
    }

    private function traverseArray(ArrayNode $node, NodeJsonPath $path): void
    {
        $i = 0;

        while ($i < count($node->items)) {
            $result = $this->traverseNode($node->items[$i], $path->childArrayIndex($i));

            if ($result instanceof NodeJsonRemoval) {
                array_splice($node->items, $i, 1);
                $this->changeSet->markChanged($node);
                continue;
            }

            if (! $result instanceof ArrayItemNode) {
                throw new LogicException('ArrayNode children must be ArrayItemNode.');
            }

            $node->items[$i] = $result;
            $i++;
        }
    }

    private function traverseArrayItem(ArrayItemNode $node, NodeJsonPath $path): void
    {
        $result = $this->traverseNode($node->value, $path);

        if ($result instanceof NodeJsonRemoval) {
            throw new LogicException('Cannot remove array value directly. Remove the ArrayItemNode instead.');
        }

        $node->value = $result;
    }
}
