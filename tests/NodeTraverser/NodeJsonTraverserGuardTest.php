<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\NodeTraverser;

use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeTraverser\NodeJsonTraversalResult;
use Boundwize\JsonRecast\NodeTraverser\NodeJsonTraverser;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\JsonRecast\Parser\JsonParser;
use Boundwize\JsonRecast\Value\JsonValue;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class NodeJsonTraverserGuardTest extends TestCase
{
    public function testItForbidsRemovingRootBeforeTraverse(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot remove root node during beforeTraverse().');

        $this->traverse(new StringNode('root'), new class extends NodeJsonVisitorAbstract {
            public function beforeTraverse(NodeJson $nodeJson): int
            {
                return NodeJsonVisitor::REMOVE_NODE;
            }
        });
    }

    public function testItCanReplaceRootBeforeTraverse(): void
    {
        $nodeJsonTraversalResult = $this->traverse(new StringNode('old'), new class extends NodeJsonVisitorAbstract {
            public function beforeTraverse(NodeJson $nodeJson): NodeJson
            {
                return new StringNode('new');
            }
        });

        $this->assertInstanceOf(StringNode::class, $nodeJsonTraversalResult->node);
        $this->assertSame('new', $nodeJsonTraversalResult->node->value);
        $this->assertTrue($nodeJsonTraversalResult->changeSet->isChanged($nodeJsonTraversalResult->node));
    }

    public function testItForbidsRemovingRootAfterTraverse(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot remove root node during afterTraverse().');

        $this->traverse(new StringNode('root'), new class extends NodeJsonVisitorAbstract {
            public function afterTraverse(NodeJson $nodeJson): int
            {
                return NodeJsonVisitor::REMOVE_NODE;
            }
        });
    }

    public function testItCanReplaceRootAfterTraverse(): void
    {
        $nodeJsonTraversalResult = $this->traverse(new StringNode('old'), new class extends NodeJsonVisitorAbstract {
            public function afterTraverse(NodeJson $nodeJson): NodeJson
            {
                return new StringNode('new');
            }
        });

        $this->assertInstanceOf(StringNode::class, $nodeJsonTraversalResult->node);
        $this->assertSame('new', $nodeJsonTraversalResult->node->value);
        $this->assertTrue($nodeJsonTraversalResult->changeSet->isChanged($nodeJsonTraversalResult->node));
    }

    public function testItForbidsRemovingRootOnEnter(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot remove root node.');

        $this->traverse(new StringNode('root'), new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): int
            {
                return NodeJsonVisitor::REMOVE_NODE;
            }
        });
    }

    public function testItForbidsRemovingRootOnLeave(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot remove root node.');

        $this->traverse(new StringNode('root'), new class extends NodeJsonVisitorAbstract {
            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): int
            {
                return NodeJsonVisitor::REMOVE_NODE;
            }
        });
    }

    public function testItRejectsUnknownIntegerVisitorAction(): void
    {
        $reflectionMethod = new ReflectionMethod(NodeJsonTraverser::class, 'isRemoveNode');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown node visitor action.');

        $reflectionMethod->invoke(new NodeJsonTraverser(), 2);
    }

    public function testItForbidsRemovingDocumentValueDirectly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot remove document value directly.');

        $this->traverse((new JsonParser())->parse('"value"'), new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if (! $nodeJson instanceof StringNode) {
                    return null;
                }

                return NodeJsonVisitor::REMOVE_NODE;
            }
        });
    }

    public function testItRequiresObjectChildrenToStayObjectItems(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ObjectNode children must be ObjectItemNode.');

        $this->traverse((new JsonParser())->parse('{"name":"value"}'), new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof ObjectItemNode) {
                    return null;
                }

                return new StringNode('invalid');
            }
        });
    }

    public function testItForbidsRemovingObjectKeyDirectly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot remove object key directly.');

        $this->traverse((new JsonParser())->parse('{"name":"value"}'), new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if (! $nodeJson instanceof StringNode || $nodeJson->value !== 'name') {
                    return null;
                }

                return NodeJsonVisitor::REMOVE_NODE;
            }
        });
    }

    public function testItRequiresObjectKeyToStayString(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Object item key must be StringNode.');

        $this->traverse((new JsonParser())->parse('{"name":"value"}'), new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof StringNode || $nodeJson->value !== 'name') {
                    return null;
                }

                return new NumberNode('1');
            }
        });
    }

    public function testItRequiresArrayChildrenToStayArrayItems(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ArrayNode children must be ArrayItemNode.');

        $this->traverse((new JsonParser())->parse('["value"]'), new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof ArrayItemNode) {
                    return null;
                }

                return new StringNode('invalid');
            }
        });
    }

    public function testItForbidsRemovingArrayValueDirectly(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot remove array value directly. Remove the ArrayItemNode instead.');

        $this->traverse((new JsonParser())->parse('["value"]'), new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if (! $nodeJson instanceof StringNode) {
                    return null;
                }

                return NodeJsonVisitor::REMOVE_NODE;
            }
        });
    }

    public function testItRejectsCyclicNodeTree(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"a": {"b": 1}}');

        $rootObject = $jsonDocument->value;
        $this->assertInstanceOf(ObjectNode::class, $rootObject);

        $objectItemNode = $rootObject->get('a');
        $this->assertInstanceOf(ObjectItemNode::class, $objectItemNode);

        $nestedObject = $objectItemNode->value;
        $this->assertInstanceOf(ObjectNode::class, $nestedObject);

        $nestedObject->set('self', $rootObject);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cyclic JSON AST detected.');

        $this->traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
        });
    }

    public function testItRejectsNodeTreeThatExceedsMaximumNestingDepth(): void
    {
        $nodeJson = JsonValue::from([[[0]]], maximumDepth: 4);

        $nodeJsonTraverser = new NodeJsonTraverser(maximumDepth: 3);
        $nodeJsonTraverser->addVisitor(new class extends NodeJsonVisitorAbstract {
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        $nodeJsonTraverser->traverse($nodeJson);
    }

    public function testItTraversesContainerWithScalarWithinMaximumNestingDepth(): void
    {
        $nodeJson = JsonValue::from([1], maximumDepth: 2);

        $nodeJsonTraverser = new NodeJsonTraverser(maximumDepth: 2);
        $nodeJsonTraverser->addVisitor(new class extends NodeJsonVisitorAbstract {
        });

        $this->assertSame($nodeJson, $nodeJsonTraverser->traverse($nodeJson)->node);
    }

    public function testItTraversesNodeSharedBetweenSiblings(): void
    {
        // the guard tracks the active path only, so legal aliasing — the same
        // node under two sibling items — must keep traversing
        $sharedArrayNode = new ArrayNode([]);
        $arrayNode       = new ArrayNode([
            new ArrayItemNode($sharedArrayNode),
            new ArrayItemNode($sharedArrayNode),
        ]);

        $this->assertSame($arrayNode, $this->traverse($arrayNode, new class extends NodeJsonVisitorAbstract {
        })->node);
    }

    public function testItRejectsNonPositiveMaximumDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum depth must be greater than 0.');

        new NodeJsonTraverser(maximumDepth: 0);
    }

    private function traverse(
        NodeJson $nodeJson,
        NodeJsonVisitorAbstract $nodeJsonVisitorAbstract,
    ): NodeJsonTraversalResult {
        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($nodeJsonVisitorAbstract);

        return $nodeJsonTraverser->traverse($nodeJson);
    }
}
