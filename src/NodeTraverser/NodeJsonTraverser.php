<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeTraverser;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use LogicException;

use function array_splice;
use function count;
use function is_int;

final class NodeJsonTraverser
{
    /** @var list<NodeJsonVisitor> */
    private array $visitors = [];

    private NodeChangeSet $nodeChangeSet;

    public function __construct()
    {
        $this->nodeChangeSet = new NodeChangeSet();
    }

    public function addVisitor(NodeJsonVisitor $nodeJsonVisitor): void
    {
        $this->visitors[] = $nodeJsonVisitor;
    }

    public function traverse(NodeJson $nodeJson): NodeJsonTraversalResult
    {
        $this->nodeChangeSet = new NodeChangeSet();

        foreach ($this->visitors as $visitor) {
            $result = $visitor->beforeTraverse($nodeJson);

            if ($this->isRemoveNode($result)) {
                throw new LogicException('Cannot remove root node during beforeTraverse().');
            }

            if ($result instanceof NodeJson) {
                $result = $this->preserveDocumentFraming($nodeJson, $result);
                $this->nodeChangeSet->markChanged($result);
                $nodeJson = $result;
            }
        }

        $traverseResult = $this->traverseNode($nodeJson, new NodeJsonPath());

        if ($traverseResult === NodeJsonVisitor::REMOVE_NODE) {
            throw new LogicException('Cannot remove root node.');
        }

        $nodeJson = $traverseResult;

        foreach ($this->visitors as $visitor) {
            $result = $visitor->afterTraverse($nodeJson);

            if ($this->isRemoveNode($result)) {
                throw new LogicException('Cannot remove root node during afterTraverse().');
            }

            if ($result instanceof NodeJson) {
                $result = $this->preserveDocumentFraming($nodeJson, $result);
                $this->nodeChangeSet->markChanged($result);
                $nodeJson = $result;
            }
        }

        return new NodeJsonTraversalResult($nodeJson, $this->nodeChangeSet);
    }

    /**
     * @return NodeJson|NodeJsonVisitor::REMOVE_NODE
     */
    private function traverseNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): NodeJson|int
    {
        foreach ($this->visitors as $visitor) {
            $result = $visitor->enterNode($nodeJson, $nodeJsonPath);

            if ($this->isRemoveNode($result)) {
                return NodeJsonVisitor::REMOVE_NODE;
            }

            if ($result instanceof NodeJson) {
                $result = $this->preserveDocumentFraming($nodeJson, $result);
                $this->nodeChangeSet->markChanged($result);
                $nodeJson = $result;
            }
        }

        if ($nodeJson instanceof JsonDocument) {
            $this->traverseDocument($nodeJson, $nodeJsonPath);
        } elseif ($nodeJson instanceof ObjectNode) {
            $this->traverseObject($nodeJson, $nodeJsonPath);
        } elseif ($nodeJson instanceof ObjectItemNode) {
            $this->traverseObjectItem($nodeJson, $nodeJsonPath);
        } elseif ($nodeJson instanceof ArrayNode) {
            $this->traverseArray($nodeJson, $nodeJsonPath);
        } elseif ($nodeJson instanceof ArrayItemNode) {
            $this->traverseArrayItem($nodeJson, $nodeJsonPath);
        }

        foreach ($this->visitors as $visitor) {
            $result = $visitor->leaveNode($nodeJson, $nodeJsonPath);

            if ($this->isRemoveNode($result)) {
                return NodeJsonVisitor::REMOVE_NODE;
            }

            if ($result instanceof NodeJson) {
                $result = $this->preserveDocumentFraming($nodeJson, $result);
                $this->nodeChangeSet->markChanged($result);
                $nodeJson = $result;
            }
        }

        return $nodeJson;
    }

    private function traverseDocument(JsonDocument $jsonDocument, NodeJsonPath $nodeJsonPath): void
    {
        $result = $this->traverseNode($jsonDocument->value, $nodeJsonPath);

        if ($result === NodeJsonVisitor::REMOVE_NODE) {
            throw new LogicException('Cannot remove document value directly.');
        }

        $jsonDocument->value = $result;
    }

    private function traverseObject(ObjectNode $objectNode, NodeJsonPath $nodeJsonPath): void
    {
        $i = 0;

        while ($i < count($objectNode->items)) {
            $result = $this->traverseNode($objectNode->items[$i], $nodeJsonPath);

            if ($result === NodeJsonVisitor::REMOVE_NODE) {
                array_splice($objectNode->items, $i, 1);
                $this->nodeChangeSet->markChanged($objectNode);
                continue;
            }

            if (! $result instanceof ObjectItemNode) {
                throw new LogicException('ObjectNode children must be ObjectItemNode.');
            }

            $objectNode->items[$i] = $result;
            $i++;
        }
    }

    private function traverseObjectItem(ObjectItemNode $objectItemNode, NodeJsonPath $nodeJsonPath): void
    {
        $keyResult = $this->traverseNode($objectItemNode->key, $nodeJsonPath);

        if ($keyResult === NodeJsonVisitor::REMOVE_NODE) {
            throw new LogicException('Cannot remove object key directly.');
        }

        if (! $keyResult instanceof StringNode) {
            throw new LogicException('Object item key must be StringNode.');
        }

        $objectItemNode->key = $keyResult;

        $valuePath   = $nodeJsonPath->childObjectKey($objectItemNode->key->value);
        $valueResult = $this->traverseNode($objectItemNode->value, $valuePath);

        if ($valueResult === NodeJsonVisitor::REMOVE_NODE) {
            throw new LogicException('Cannot remove object value directly. Remove the ObjectItemNode instead.');
        }

        $objectItemNode->value = $valueResult;
    }

    private function traverseArray(ArrayNode $arrayNode, NodeJsonPath $nodeJsonPath): void
    {
        $i = 0;

        while ($i < count($arrayNode->items)) {
            $result = $this->traverseNode($arrayNode->items[$i], $nodeJsonPath->childArrayIndex($i));

            if ($result === NodeJsonVisitor::REMOVE_NODE) {
                array_splice($arrayNode->items, $i, 1);
                $this->nodeChangeSet->markChanged($arrayNode);
                continue;
            }

            if (! $result instanceof ArrayItemNode) {
                throw new LogicException('ArrayNode children must be ArrayItemNode.');
            }

            $arrayNode->items[$i] = $result;
            $i++;
        }
    }

    private function traverseArrayItem(ArrayItemNode $arrayItemNode, NodeJsonPath $nodeJsonPath): void
    {
        $result = $this->traverseNode($arrayItemNode->value, $nodeJsonPath);

        if ($result === NodeJsonVisitor::REMOVE_NODE) {
            throw new LogicException('Cannot remove array value directly. Remove the ArrayItemNode instead.');
        }

        $arrayItemNode->value = $result;
    }

    private function isRemoveNode(null|NodeJson|int $result): bool
    {
        if ($result === NodeJsonVisitor::REMOVE_NODE) {
            return true;
        }

        if (is_int($result)) {
            throw new LogicException('Unknown node visitor action.');
        }

        return false;
    }

    private function preserveDocumentFraming(NodeJson $previous, NodeJson $replacement): NodeJson
    {
        if (
            ! $previous instanceof JsonDocument
            || ! $replacement instanceof JsonDocument
            || $previous === $replacement
        ) {
            return $replacement;
        }

        if ($replacement->beforeValue === '') {
            $replacement->beforeValue = $previous->beforeValue;
        }

        if ($replacement->afterValue === '') {
            $replacement->afterValue = $previous->afterValue;
        }

        $this->copyDocumentAttribute($previous, $replacement, NodeAttributes::NEWLINE);
        $this->copyDocumentAttribute($previous, $replacement, NodeAttributes::TRAILING_NEWLINE);

        return $replacement;
    }

    private function copyDocumentAttribute(JsonDocument $source, JsonDocument $target, string $attribute): void
    {
        if ($target->hasAttribute($attribute) || ! $source->hasAttribute($attribute)) {
            return;
        }

        $target->setAttribute($attribute, $source->getAttribute($attribute));
    }
}
