<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\NodeTraverser;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodePath\NodeJsonPathSegment;
use Boundwize\JsonRecast\NodeTraverser\NodeJsonTraversalResult;
use Boundwize\JsonRecast\NodeTraverser\NodeJsonTraverser;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\JsonRecast\Parser\JsonParser;
use Boundwize\JsonRecast\Printer\JsonPrettyPrinter;
use Boundwize\JsonRecast\Value\JsonValue;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function is_int;

final class NodeJsonTraverserTest extends TestCase
{
    public function testNullReturnRecordsNoChange(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"name":"old"}');

        $nodeJsonTraversalResult = $this->traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
        });

        $this->assertFalse($nodeJsonTraversalResult->changeSet->isChanged($jsonDocument));
    }

    public function testReturnedReplacementNodeIsRecordedAsChanged(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"name":"old"}');
        $visitor      = new class extends NodeJsonVisitorAbstract {
            public ?StringNode $replacement = null;

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof StringNode || $nodeJson->value !== 'old') {
                    return null;
                }

                return $this->replacement = new StringNode('new');
            }
        };

        $nodeJsonTraversalResult = $this->traverse($jsonDocument, $visitor);

        $this->assertInstanceOf(StringNode::class, $visitor->replacement);
        $this->assertTrue($nodeJsonTraversalResult->changeSet->isChanged($visitor->replacement));
    }

    public function testReturningSameNodeRecordsChange(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"name":"boundwize/jsonrecast"}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $objectNode = $jsonDocument->value;

        $nodeJsonTraversalResult = $this->traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof ObjectNode || ! $nodeJsonPath->isRoot()) {
                    return null;
                }

                $nodeJson->set('license', JsonValue::from('MIT'));

                return $nodeJson;
            }
        });

        $this->assertTrue($nodeJsonTraversalResult->changeSet->isChanged($objectNode));
    }

    public function testDocumentReplacementKeepsExistingFramingAttributes(): void
    {
        $jsonDocument = new JsonDocument(new NumberNode('1'), afterValue: ' ');
        $jsonDocument->setAttribute(NodeAttributes::NEWLINE, "\r\n");
        $jsonDocument->setAttribute(NodeAttributes::TRAILING_NEWLINE, true);

        $nodeJsonTraversalResult = $this->traverse(
            (new JsonParser())->parse("0\n"),
            new class ($jsonDocument) extends NodeJsonVisitorAbstract {
                public function __construct(
                    private readonly JsonDocument $jsonDocument,
                ) {
                }

                public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if (! $nodeJson instanceof JsonDocument || ! $nodeJsonPath->isRoot()) {
                        return null;
                    }

                    return $this->jsonDocument;
                }
            },
        );

        $this->assertSame($jsonDocument, $nodeJsonTraversalResult->node);
        $this->assertSame("\r\n", $jsonDocument->getAttribute(NodeAttributes::NEWLINE));
        $this->assertTrue($jsonDocument->getAttribute(NodeAttributes::TRAILING_NEWLINE));
        $this->assertSame(' ', $jsonDocument->afterValue);
    }

    public function testNoHasChangedAttributeExists(): void
    {
        $this->assertFalse((new ReflectionClass(NodeAttributes::class))->hasConstant('HAS_CHANGED'));
    }

    public function testItReplacesStringNode(): void
    {
        $nodeJsonTraversalResult = $this->replaceStringValue(
            (new JsonParser())->parse('{"name":"old"}'),
            'old',
            'new',
        );

        $this->assertSame(
            <<<'JSON'
{
    "name": "new"
}
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testItUpdatesObjectItemValue(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"name":"old"}');

        $nodeJsonTraversalResult = $this->traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof ObjectItemNode || $nodeJson->key->value !== 'name') {
                    return null;
                }

                $nodeJson->value = new StringNode('new');

                return $nodeJson;
            }
        });

        $this->assertSame(
            <<<'JSON'
{
    "name": "new"
}
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testItRemovesObjectItem(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"name":"boundwize/jsonrecast","minimum-stability":"dev"}');

        $nodeJsonTraversalResult = $this->traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if (! $nodeJson instanceof ObjectItemNode || $nodeJson->key->value !== 'minimum-stability') {
                    return null;
                }

                return NodeJsonVisitor::REMOVE_NODE;
            }
        });

        $this->assertSame(
            <<<'JSON'
{
    "name": "boundwize/jsonrecast"
}
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testItRemovesArrayItem(): void
    {
        $jsonDocument = (new JsonParser())->parse('["json","temporary"]');

        $nodeJsonTraversalResult = $this->traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if (! $nodeJson instanceof ArrayItemNode || ! $nodeJson->value instanceof StringNode) {
                    return null;
                }

                if ($nodeJson->value->value !== 'temporary') {
                    return null;
                }

                return NodeJsonVisitor::REMOVE_NODE;
            }
        });

        $this->assertSame(<<<'JSON'
[
    "json"
]
JSON, (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node));
    }

    public function testArrayIndexesAreReindexedAfterVisitorRemovalAndAddition(): void
    {
        $jsonDocument      = (new JsonParser())->parse('["keep","remove","after"]');
        $indexVisitor      = new class extends NodeJsonVisitorAbstract {
            /** @var array<int, string> */
            public array $valuesByIndex = [];

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                $last = $nodeJsonPath->last();

                if (
                    ! $nodeJson instanceof StringNode
                    || ! $last instanceof NodeJsonPathSegment
                    || ! is_int($last->value)
                ) {
                    return null;
                }

                $this->valuesByIndex[$last->value] = $nodeJson->value;

                return null;
            }
        };
        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor(new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof ArrayNode || ! $nodeJsonPath->isRoot()) {
                    return null;
                }

                $nodeJson->removeAt(1);
                $nodeJson->append(JsonValue::from('added'));

                return $nodeJson;
            }
        });
        $nodeJsonTraverser->addVisitor($indexVisitor);

        $nodeJsonTraversalResult = $nodeJsonTraverser->traverse($jsonDocument);

        $this->assertSame(['keep', 'after', 'added'], $indexVisitor->valuesByIndex);
        $this->assertSame(<<<'JSON'
[
    "keep",
    "after",
    "added"
]
JSON, (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node));
    }

    public function testItForbidsRemovingStringValueDirectly(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"name":"old"}');

        $this->expectException(LogicException::class);

        $this->traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if (! $nodeJson instanceof StringNode || $nodeJson->value !== 'old') {
                    return null;
                }

                return NodeJsonVisitor::REMOVE_NODE;
            }
        });
    }

    public function testItPassesPathToObjectValue(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"name":"old"}');
        $visitor      = new class extends NodeJsonVisitorAbstract {
            public bool $sawNameValue = false;

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (
                    $nodeJson instanceof StringNode
                    && $nodeJson->value === 'old'
                    && $nodeJsonPath->isObjectValue('name')
                ) {
                    $this->sawNameValue = true;
                }

                return null;
            }
        };

        $this->traverse($jsonDocument, $visitor);

        $this->assertTrue($visitor->sawNameValue);
    }

    public function testObjectValuePathUsesCurrentKeyWhenKeyIsRenamedOnItemEnter(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"old":{"target":"value"}}');
        $visitor      = new class extends NodeJsonVisitorAbstract {
            public bool $sawCurrentPath = false;

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if ($nodeJson instanceof ObjectItemNode && $nodeJson->key->value === 'old') {
                    $nodeJson->key = new StringNode('new');

                    return $nodeJson;
                }

                if (
                    $nodeJson instanceof StringNode
                    && $nodeJson->value === 'value'
                    && $nodeJsonPath->matches(['new', 'target'])
                ) {
                    $this->sawCurrentPath = true;
                }

                return null;
            }
        };

        $nodeJsonTraversalResult = $this->traverse($jsonDocument, $visitor);

        $this->assertTrue($visitor->sawCurrentPath);
        $this->assertSame(
            <<<'JSON'
{
    "new": {
        "target": "value"
    }
}
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testObjectValueLeavePathUsesCurrentKeyWhenKeyIsRenamedOnItemEnter(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"old":{"target":"value"}}');
        $visitor      = new class extends NodeJsonVisitorAbstract {
            public bool $sawCurrentPath = false;

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if ($nodeJson instanceof ObjectItemNode && $nodeJson->key->value === 'old') {
                    $nodeJson->key = new StringNode('new');

                    return $nodeJson;
                }

                return null;
            }

            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (
                    $nodeJson instanceof StringNode
                    && $nodeJson->value === 'value'
                    && $nodeJsonPath->matches(['new', 'target'])
                ) {
                    $this->sawCurrentPath = true;
                }

                return null;
            }
        };

        $nodeJsonTraversalResult = $this->traverse($jsonDocument, $visitor);

        $this->assertTrue($visitor->sawCurrentPath);
        $this->assertSame(
            <<<'JSON'
{
    "new": {
        "target": "value"
    }
}
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testObjectValuePathUsesCurrentKeyWhenKeyIsRenamedOnKeyLeave(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"old":{"target":"value"}}');
        $visitor      = new class extends NodeJsonVisitorAbstract {
            public bool $sawCurrentPath = false;

            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (
                    $nodeJson instanceof StringNode
                    && $nodeJson->value === 'old'
                    && $nodeJsonPath->isRoot()
                ) {
                    return new StringNode('new');
                }

                if (
                    $nodeJson instanceof StringNode
                    && $nodeJson->value === 'value'
                    && $nodeJsonPath->matches(['new', 'target'])
                ) {
                    $this->sawCurrentPath = true;
                }

                return null;
            }
        };

        $nodeJsonTraversalResult = $this->traverse($jsonDocument, $visitor);

        $this->assertTrue($visitor->sawCurrentPath);
        $this->assertSame(
            <<<'JSON'
{
    "new": {
        "target": "value"
    }
}
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testItPassesPathToArrayValue(): void
    {
        $jsonDocument = (new JsonParser())->parse('["a", "b"]');
        $visitor      = new class extends NodeJsonVisitorAbstract {
            /** @var list<int> */
            public array $indexes = [];

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                $last = $nodeJsonPath->last();

                if (
                    $nodeJson instanceof StringNode
                    && $last instanceof NodeJsonPathSegment
                    && is_int($last->value)
                    && ($nodeJsonPath->isArrayValue(0) || $nodeJsonPath->isArrayValue(1))
                ) {
                    $this->indexes[] = $last->value;
                }

                return null;
            }
        };

        $this->traverse($jsonDocument, $visitor);

        $this->assertSame([0, 1], $visitor->indexes);
    }

    public function testItPassesNestedMixedPath(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"items":[{"name":"first"}]}');
        $visitor      = new class extends NodeJsonVisitorAbstract {
            public bool $sawPath = false;

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (
                    $nodeJson instanceof StringNode
                    && $nodeJson->value === 'first'
                    && $nodeJsonPath->matches(['items', 0, 'name'])
                ) {
                    $this->sawPath = true;
                }

                return null;
            }
        };

        $this->traverse($jsonDocument, $visitor);

        $this->assertTrue($visitor->sawPath);
    }

    public function testItTraversesRecursiveObjects(): void
    {
        $nodeJsonTraversalResult = $this->replaceStringValue(
            (new JsonParser())->parse('{"a":{"b":{"c":"old"}}}'),
            'old',
            'new',
        );

        $this->assertSame(
            <<<'JSON'
{
    "a": {
        "b": {
            "c": "new"
        }
    }
}
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testItTraversesRecursiveArrays(): void
    {
        $nodeJsonTraversalResult = $this->replaceStringValue(
            (new JsonParser())->parse('[[["old"]]]'),
            'old',
            'new',
        );

        $this->assertSame(
            <<<'JSON'
[
    [
        [
            "new"
        ]
    ]
]
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testItTraversesMixedRecursiveArraysAndObjects(): void
    {
        $nodeJsonTraversalResult = $this->replaceStringValue(
            (new JsonParser())->parse('{"items":[{"values":["old"]}]}'),
            'old',
            'new',
        );

        $this->assertSame(
            <<<'JSON'
{
    "items": [
        {
            "values": [
                "new"
            ]
        }
    ]
}
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testObjectNodeSetUpdatesExistingKey(): void
    {
        $nodeJsonTraversalResult = $this->mutateRootObject(
            '{"license":"GPL"}',
            static function (ObjectNode $objectNode): void {
                $objectNode->set('license', JsonValue::from('MIT'));
            },
        );

        $this->assertSame(
            <<<'JSON'
{
    "license": "MIT"
}
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testObjectNodeSetAddsMissingKey(): void
    {
        $nodeJsonTraversalResult = $this->mutateRootObject(
            '{"name":"boundwize/jsonrecast"}',
            static function (ObjectNode $objectNode): void {
                $objectNode->set('license', JsonValue::from('MIT'));
            },
        );

        $this->assertSame(
            <<<'JSON'
{
    "name": "boundwize/jsonrecast",
    "license": "MIT"
}
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testObjectNodeRemoveRemovesKey(): void
    {
        $nodeJsonTraversalResult = $this->mutateRootObject(
            '{"name":"boundwize/jsonrecast","minimum-stability":"dev"}',
            static function (ObjectNode $objectNode): void {
                $objectNode->remove('minimum-stability');
            },
        );

        $this->assertSame(
            <<<'JSON'
{
    "name": "boundwize/jsonrecast"
}
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testArrayNodeAppendAddsValue(): void
    {
        $nodeJsonTraversalResult = $this->mutateRootArray(
            '["json"]',
            static function (ArrayNode $arrayNode): void {
                $arrayNode->append(JsonValue::from('ast'));
            },
        );

        $this->assertSame(
            <<<'JSON'
[
    "json",
    "ast"
]
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testArrayNodeInsertAddsValueAtIndex(): void
    {
        $nodeJsonTraversalResult = $this->mutateRootArray(
            '["json","parser"]',
            static function (ArrayNode $arrayNode): void {
                $arrayNode->insert(1, JsonValue::from('ast'));
            },
        );

        $this->assertSame(
            <<<'JSON'
[
    "json",
    "ast",
    "parser"
]
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testArrayNodeRemoveAtRemovesValue(): void
    {
        $nodeJsonTraversalResult = $this->mutateRootArray(
            '["json","temporary"]',
            static function (ArrayNode $arrayNode): void {
                $arrayNode->removeAt(1);
            },
        );

        $this->assertSame(
            <<<'JSON'
[
    "json"
]
JSON,
            (new JsonPrettyPrinter())->print($nodeJsonTraversalResult->node),
        );
    }

    public function testMutationWithoutReturnIsNotTracked(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"name":"boundwize/jsonrecast"}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $objectNode = $jsonDocument->value;

        $nodeJsonTraversalResult = $this->traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if ($nodeJson instanceof ObjectNode && $nodeJsonPath->isRoot()) {
                    $nodeJson->set('license', JsonValue::from('MIT'));
                }

                return null;
            }
        });

        $this->assertFalse($nodeJsonTraversalResult->changeSet->isChanged($objectNode));
    }

    private function replaceStringValue(NodeJson $nodeJson, string $old, string $new): NodeJsonTraversalResult
    {
        return $this->traverse($nodeJson, new class ($old, $new) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly string $old,
                private readonly string $new,
            ) {
            }

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof StringNode || $nodeJson->value !== $this->old) {
                    return null;
                }

                return new StringNode($this->new);
            }
        });
    }

    /**
     * @param callable(ObjectNode): void $mutation
     */
    private function mutateRootObject(string $source, callable $mutation): NodeJsonTraversalResult
    {
        $mutation     = Closure::fromCallable($mutation);
        $jsonDocument = (new JsonParser())->parse($source);

        return $this->traverse($jsonDocument, new class ($mutation) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly Closure $mutation,
            ) {
            }

            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof ObjectNode || ! $nodeJsonPath->isRoot()) {
                    return null;
                }

                ($this->mutation)($nodeJson);

                return $nodeJson;
            }
        });
    }

    /**
     * @param callable(ArrayNode): void $mutation
     */
    private function mutateRootArray(string $source, callable $mutation): NodeJsonTraversalResult
    {
        $mutation     = Closure::fromCallable($mutation);
        $jsonDocument = (new JsonParser())->parse($source);

        return $this->traverse($jsonDocument, new class ($mutation) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly Closure $mutation,
            ) {
            }

            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof ArrayNode || ! $nodeJsonPath->isRoot()) {
                    return null;
                }

                ($this->mutation)($nodeJson);

                return $nodeJson;
            }
        });
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
