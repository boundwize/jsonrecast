<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeTraverser;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\Helper\WhitespaceHelper;
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
    private const ITEM_SOURCE_ATTRIBUTES = [
        NodeAttributes::START_OFFSET,
        NodeAttributes::END_OFFSET,
        NodeAttributes::DEPTH,
        NodeAttributes::INDENT,
        NodeAttributes::NEWLINE,
        NodeAttributes::ORIGINAL_TEXT,
    ];

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
                $result = $this->preserveReplacementFraming($nodeJson, $result);
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
                $result = $this->preserveReplacementFraming($nodeJson, $result);
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
                $result = $this->preserveReplacementFraming($nodeJson, $result);
                $this->nodeChangeSet->markChanged($result);
                $nodeJson = $result;
            }
        }

        if ($nodeJson instanceof JsonDocument) {
            $this->traverseDocument($nodeJson, $nodeJsonPath);
        } elseif ($nodeJson instanceof ObjectNode || $nodeJson instanceof ArrayNode) {
            $this->traverseContainer($nodeJson, $nodeJsonPath);
        } elseif ($nodeJson instanceof ObjectItemNode) {
            $this->traverseObjectItem($nodeJson, $nodeJsonPath);
        } elseif ($nodeJson instanceof ArrayItemNode) {
            $this->traverseArrayItem($nodeJson, $nodeJsonPath);
        }

        foreach ($this->visitors as $visitor) {
            $result = $visitor->leaveNode($nodeJson, $nodeJsonPath);

            if ($this->isRemoveNode($result)) {
                return NodeJsonVisitor::REMOVE_NODE;
            }

            if ($result instanceof NodeJson) {
                $result = $this->preserveReplacementFraming($nodeJson, $result);
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

    private function traverseContainer(ObjectNode|ArrayNode $containerNode, NodeJsonPath $nodeJsonPath): void
    {
        $i = 0;

        while ($i < count($containerNode->items)) {
            $childPath = $containerNode instanceof ArrayNode
                ? $nodeJsonPath->childArrayIndex($i)
                : $nodeJsonPath;
            $result    = $this->traverseNode($containerNode->items[$i], $childPath);

            if ($result === NodeJsonVisitor::REMOVE_NODE) {
                array_splice($containerNode->items, $i, 1);

                if ($containerNode->items === []) {
                    if ($containerNode instanceof ObjectNode) {
                        $containerNode->afterOpenBrace = $containerNode->beforeCloseBrace;
                    } else {
                        $containerNode->afterOpenBracket = $containerNode->beforeCloseBracket;
                    }
                } elseif ($i === 0) {
                    $firstItem           = $containerNode->items[0];
                    $firstItemWhitespace = $firstItem instanceof ObjectItemNode
                        ? $firstItem->beforeKey
                        : $firstItem->beforeValue;

                    if ($containerNode instanceof ObjectNode) {
                        $containerNode->afterOpenBrace = WhitespaceHelper::openingBeforePromotedItem(
                            $firstItemWhitespace,
                            $containerNode->afterOpenBrace,
                        );
                    } else {
                        $containerNode->afterOpenBracket = WhitespaceHelper::openingBeforePromotedItem(
                            $firstItemWhitespace,
                            $containerNode->afterOpenBracket,
                        );
                    }
                }

                $this->nodeChangeSet->markChanged($containerNode);
                continue;
            }

            if ($containerNode instanceof ObjectNode && ! $result instanceof ObjectItemNode) {
                throw new LogicException('ObjectNode children must be ObjectItemNode.');
            }

            if ($containerNode instanceof ArrayNode && ! $result instanceof ArrayItemNode) {
                throw new LogicException('ArrayNode children must be ArrayItemNode.');
            }

            $containerNode->items[$i] = $result;
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

    private function preserveReplacementFraming(NodeJson $previous, NodeJson $replacement): NodeJson
    {
        if ($previous === $replacement) {
            return $replacement;
        }

        if ($previous instanceof JsonDocument && $replacement instanceof JsonDocument) {
            return $this->preserveDocumentFraming($previous, $replacement);
        }

        if ($previous instanceof ArrayItemNode && $replacement instanceof ArrayItemNode) {
            return $this->preserveArrayItemFraming($previous, $replacement);
        }

        if ($previous instanceof ObjectItemNode && $replacement instanceof ObjectItemNode) {
            return $this->preserveObjectItemFraming($previous, $replacement);
        }

        return $replacement;
    }

    private function preserveDocumentFraming(JsonDocument $previous, JsonDocument $replacement): JsonDocument
    {
        // a parsed replacement carries its donor document's root framing, which must not
        // survive the move; a synthetic replacement keeps whatever framing was set on it
        if ($replacement->hasAttribute(NodeAttributes::SOURCE)) {
            $replacement->beforeValue = $previous->beforeValue;
            $replacement->afterValue  = $previous->afterValue;

            $this->adoptAttribute($previous, $replacement, NodeAttributes::NEWLINE);
            $this->adoptAttribute($previous, $replacement, NodeAttributes::INDENT);
            $this->adoptAttribute($previous, $replacement, NodeAttributes::TRAILING_NEWLINE);

            return $replacement;
        }

        if ($replacement->beforeValue === '') {
            $replacement->beforeValue = $previous->beforeValue;
        }

        if ($replacement->afterValue === '') {
            $replacement->afterValue = $previous->afterValue;
        }

        $this->copyAttribute($previous, $replacement, NodeAttributes::NEWLINE);
        $this->copyAttribute($previous, $replacement, NodeAttributes::INDENT);
        $this->copyAttribute($previous, $replacement, NodeAttributes::TRAILING_NEWLINE);

        return $replacement;
    }

    private function preserveArrayItemFraming(ArrayItemNode $previous, ArrayItemNode $replacement): ArrayItemNode
    {
        $replacement->beforeValue = $previous->beforeValue;
        $replacement->afterValue  = $previous->afterValue;

        $this->adoptItemSourceAttributes($previous, $replacement);

        return $replacement;
    }

    private function preserveObjectItemFraming(ObjectItemNode $previous, ObjectItemNode $replacement): ObjectItemNode
    {
        $replacement->beforeKey            = $previous->beforeKey;
        $replacement->betweenKeyAndColon   = $previous->betweenKeyAndColon;
        $replacement->betweenColonAndValue = $previous->betweenColonAndValue;
        $replacement->afterValue           = $previous->afterValue;

        $this->adoptItemSourceAttributes($previous, $replacement);

        return $replacement;
    }

    private function adoptItemSourceAttributes(
        ArrayItemNode|ObjectItemNode $source,
        ArrayItemNode|ObjectItemNode $target,
    ): void {
        foreach (self::ITEM_SOURCE_ATTRIBUTES as $attribute) {
            $this->adoptAttribute($source, $target, $attribute);
        }
    }

    private function adoptAttribute(NodeJson $source, NodeJson $target, string $attribute): void
    {
        if ($source->hasAttribute($attribute)) {
            $target->setAttribute($attribute, $source->getAttribute($attribute));

            return;
        }

        $target->removeAttribute($attribute);
    }

    private function copyAttribute(NodeJson $source, NodeJson $target, string $attribute): void
    {
        if ($target->hasAttribute($attribute) || ! $source->hasAttribute($attribute)) {
            return;
        }

        $target->setAttribute($attribute, $source->getAttribute($attribute));
    }
}
