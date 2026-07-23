<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\NodeTraverser;

use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeTraverser\NodeJsonTraverser;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\JsonRecast\Parser\JsonParser;
use LogicException;
use PHPUnit\Framework\TestCase;

final class NodeJsonTraverserStopTraversalTest extends TestCase
{
    public function testStopTraversalFromEnterNodeSkipsRemainingNodesAndLeaveNode(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"a": 1, "b": 2}');
        $visitor      = new class extends NodeJsonVisitorAbstract {
            /** @var list<string> */
            public array $calls = [];

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                $this->calls[] = 'enter:' . $this->describe($nodeJson);

                if ($nodeJson instanceof NumberNode && $nodeJson->rawValue === '1') {
                    return NodeJsonVisitor::STOP_TRAVERSAL;
                }

                return null;
            }

            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null
            {
                $this->calls[] = 'leave:' . $this->describe($nodeJson);

                return null;
            }

            private function describe(NodeJson $nodeJson): string
            {
                if ($nodeJson instanceof StringNode) {
                    return 'string(' . $nodeJson->value . ')';
                }

                if ($nodeJson instanceof NumberNode) {
                    return 'number(' . $nodeJson->rawValue . ')';
                }

                return $nodeJson::class;
            }
        };

        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($visitor);

        $nodeJsonTraversalResult = $nodeJsonTraverser->traverse($jsonDocument);

        $this->assertSame($jsonDocument, $nodeJsonTraversalResult->node);
        $this->assertSame([
            'enter:' . JsonDocument::class,
            'enter:' . ObjectNode::class,
            'enter:' . ObjectItemNode::class,
            'enter:string(a)',
            'leave:string(a)',
            'enter:number(1)',
        ], $visitor->calls);
    }

    public function testStopTraversalFromLeaveNodeSkipsRemainingNodes(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"a": 1, "b": 2}');
        $visitor      = new class extends NodeJsonVisitorAbstract {
            /** @var list<string> */
            public array $enteredNumbers = [];

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null
            {
                if ($nodeJson instanceof NumberNode) {
                    $this->enteredNumbers[] = $nodeJson->rawValue;
                }

                return null;
            }

            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if ($nodeJson instanceof NumberNode && $nodeJson->rawValue === '1') {
                    return NodeJsonVisitor::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($visitor);
        $nodeJsonTraverser->traverse($jsonDocument);

        $this->assertSame(['1'], $visitor->enteredNumbers);
    }

    public function testStopTraversalSkipsRemainingVisitorsButStillCallsAfterTraverse(): void
    {
        $jsonDocument     = (new JsonParser())->parse('{"a": 1, "b": 2}');
        $stoppingVisitor  = new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if ($nodeJson instanceof StringNode && $nodeJson->value === 'a') {
                    return NodeJsonVisitor::STOP_TRAVERSAL;
                }

                return null;
            }
        };
        $observingVisitor = new class extends NodeJsonVisitorAbstract {
            /** @var list<string> */
            public array $enteredStrings = [];

            public bool $afterTraverseCalled = false;

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null
            {
                if ($nodeJson instanceof StringNode) {
                    $this->enteredStrings[] = $nodeJson->value;
                }

                return null;
            }

            public function afterTraverse(NodeJson $nodeJson): null
            {
                $this->afterTraverseCalled = true;

                return null;
            }
        };

        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($stoppingVisitor);
        $nodeJsonTraverser->addVisitor($observingVisitor);
        $nodeJsonTraverser->traverse($jsonDocument);

        $this->assertSame([], $observingVisitor->enteredStrings);
        $this->assertTrue($observingVisitor->afterTraverseCalled);
    }

    public function testStopTraversalOnObjectKeySkipsItsValue(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"a": 1}');
        $visitor      = new class extends NodeJsonVisitorAbstract {
            /** @var list<string> */
            public array $enteredNumbers = [];

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if ($nodeJson instanceof StringNode && $nodeJson->value === 'a') {
                    return NodeJsonVisitor::STOP_TRAVERSAL;
                }

                if ($nodeJson instanceof NumberNode) {
                    $this->enteredNumbers[] = $nodeJson->rawValue;
                }

                return null;
            }
        };

        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($visitor);
        $nodeJsonTraverser->traverse($jsonDocument);

        $this->assertSame([], $visitor->enteredNumbers);
    }

    public function testStopTraversalRecordsNoChange(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"a": 1}');
        $visitor      = new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): int
            {
                return NodeJsonVisitor::STOP_TRAVERSAL;
            }
        };

        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($visitor);

        $nodeJsonTraversalResult = $nodeJsonTraverser->traverse($jsonDocument);

        $this->assertSame($jsonDocument, $nodeJsonTraversalResult->node);
        $this->assertFalse($nodeJsonTraversalResult->changeSet->isChanged($jsonDocument));
    }

    public function testStopTraversalFromBeforeTraverseThrows(): void
    {
        $visitor = new class extends NodeJsonVisitorAbstract {
            public function beforeTraverse(NodeJson $nodeJson): int
            {
                return NodeJsonVisitor::STOP_TRAVERSAL;
            }
        };

        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($visitor);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('STOP_TRAVERSAL cannot be returned from beforeTraverse().');

        $nodeJsonTraverser->traverse((new JsonParser())->parse('{"a": 1}'));
    }

    public function testStopTraversalFromAfterTraverseThrows(): void
    {
        $visitor = new class extends NodeJsonVisitorAbstract {
            public function afterTraverse(NodeJson $nodeJson): int
            {
                return NodeJsonVisitor::STOP_TRAVERSAL;
            }
        };

        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($visitor);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('STOP_TRAVERSAL cannot be returned from afterTraverse().');

        $nodeJsonTraverser->traverse((new JsonParser())->parse('{"a": 1}'));
    }

    public function testTraverserIsReusableAfterStopTraversal(): void
    {
        $visitor = new class extends NodeJsonVisitorAbstract {
            /** @var list<string> */
            public array $enteredNumbers = [];

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if (! $nodeJson instanceof NumberNode) {
                    return null;
                }

                $this->enteredNumbers[] = $nodeJson->rawValue;

                return $nodeJson->rawValue === '1' ? NodeJsonVisitor::STOP_TRAVERSAL : null;
            }
        };

        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($visitor);
        $nodeJsonTraverser->traverse((new JsonParser())->parse('[1, 2]'));
        $nodeJsonTraverser->traverse((new JsonParser())->parse('[3, 4]'));

        $this->assertSame(['1', '3', '4'], $visitor->enteredNumbers);
    }
}
