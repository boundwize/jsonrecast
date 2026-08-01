<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Guard;

use Boundwize\JsonRecast\Guard\NodeTreeGuard;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class NodeTreeGuardTest extends TestCase
{
    public function testItCannotBeConstructedForState(): void
    {
        $reflectionClass = new ReflectionClass(NodeTreeGuard::class);
        $constructor     = $reflectionClass->getConstructor();

        $this->assertInstanceOf(ReflectionMethod::class, $constructor);

        $nodeTreeGuard = $reflectionClass->newInstanceWithoutConstructor();
        $constructor->invoke($nodeTreeGuard);

        $this->assertInstanceOf(NodeTreeGuard::class, $nodeTreeGuard);
    }

    /**
     * @return iterable<string, array{NodeJson}>
     */
    public static function provideCyclicNode(): iterable
    {
        // a wrapper cycle stays at the same nesting depth forever, so the
        // depth guard alone would never terminate the tree walk
        $jsonDocument        = new JsonDocument(new NullNode());
        $jsonDocument->value = $jsonDocument;

        yield 'document referencing itself' => [$jsonDocument];

        $arrayItemNode        = new ArrayItemNode(new NullNode());
        $arrayItemNode->value = $arrayItemNode;

        yield 'array item referencing itself' => [$arrayItemNode];

        $objectItemNode        = new ObjectItemNode(new StringNode('key'), new NullNode());
        $objectItemNode->value = $objectItemNode;

        yield 'object item referencing itself' => [$objectItemNode];

        $containerItemNode        = new ArrayItemNode(new NullNode());
        $containerArrayNode       = new ArrayNode([$containerItemNode]);
        $containerItemNode->value = $containerArrayNode;

        yield 'array item referencing its own container' => [$containerArrayNode];
    }

    #[DataProvider('provideCyclicNode')]
    public function testItRejectsCyclicNodeTree(NodeJson $nodeJson): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cyclic JSON AST detected.');

        NodeTreeGuard::guard($nodeJson, maximumDepth: 512);
    }

    /**
     * @return iterable<string, array{NodeJson, string}>
     */
    public static function provideWrapperNodeInValuePosition(): iterable
    {
        $wrapperNodeFactories = [
            'JsonDocument'   => static fn (): NodeJson => new JsonDocument(new NullNode()),
            'ObjectItemNode' => static fn (): NodeJson => new ObjectItemNode(new StringNode('inner'), new NullNode()),
            'ArrayItemNode'  => static fn (): NodeJson => new ArrayItemNode(new NullNode()),
        ];

        foreach ($wrapperNodeFactories as $wrapperNodeName => $createWrapperNode) {
            $expectedMessage = $wrapperNodeName . ' cannot be used as a JSON value.';

            yield $wrapperNodeName . ' as document value' => [
                new JsonDocument($createWrapperNode()),
                $expectedMessage,
            ];

            yield $wrapperNodeName . ' as object item value' => [
                new ObjectNode([new ObjectItemNode(new StringNode('outer'), $createWrapperNode())]),
                $expectedMessage,
            ];

            yield $wrapperNodeName . ' as array item value' => [
                new ArrayNode([new ArrayItemNode($createWrapperNode())]),
                $expectedMessage,
            ];
        }
    }

    #[DataProvider('provideWrapperNodeInValuePosition')]
    public function testItRejectsWrapperNodeInValuePosition(NodeJson $nodeJson, string $expectedMessage): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        NodeTreeGuard::guard($nodeJson, maximumDepth: 512);
    }

    public function testItAllowsNodeSharedBetweenSiblings(): void
    {
        $sharedNode = new ArrayNode([]);
        $arrayNode  = new ArrayNode([
            new ArrayItemNode($sharedNode),
            new ArrayItemNode($sharedNode),
        ]);

        NodeTreeGuard::guard(new JsonDocument($arrayNode), maximumDepth: 512);

        $this->addToAssertionCount(1);
    }

    public function testItRejectsNodeTreeThatExceedsMaximumDepth(): void
    {
        $arrayNode = new ArrayNode([
            new ArrayItemNode(new ArrayNode([
                new ArrayItemNode(new NumberNode('0')),
            ])),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        NodeTreeGuard::guard($arrayNode, maximumDepth: 1);
    }

    public function testItRejectsObjectContainingArrayItem(): void
    {
        $objectNode = new ObjectNode([]);
        // Reproduce a runtime contract violation without making this test fail static analysis.
        (new ReflectionProperty($objectNode, 'items'))->setValue(
            $objectNode,
            [new ArrayItemNode(new StringNode('value'))],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ObjectNode children must be ObjectItemNode.');

        NodeTreeGuard::guard($objectNode, maximumDepth: 512);
    }

    public function testItRejectsArrayContainingObjectItem(): void
    {
        $arrayNode = new ArrayNode([]);
        // Reproduce a runtime contract violation without making this test fail static analysis.
        (new ReflectionProperty($arrayNode, 'items'))->setValue(
            $arrayNode,
            [new ObjectItemNode(new StringNode('key'), new StringNode('value'))],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ArrayNode children must be ArrayItemNode.');

        NodeTreeGuard::guard($arrayNode, maximumDepth: 512);
    }

    public function testItRejectsContainerAtMaximumDepth(): void
    {
        $objectNode = new ObjectNode([
            new ObjectItemNode(new StringNode('value'), new NumberNode('1')),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        NodeTreeGuard::guard(new JsonDocument($objectNode), maximumDepth: 1);
    }

    public function testItAllowsContainerWithScalarWithinMaximumDepth(): void
    {
        $objectNode = new ObjectNode([
            new ObjectItemNode(new StringNode('value'), new NumberNode('1')),
        ]);

        NodeTreeGuard::guard(new JsonDocument($objectNode), maximumDepth: 2);

        $this->addToAssertionCount(1);
    }
}
