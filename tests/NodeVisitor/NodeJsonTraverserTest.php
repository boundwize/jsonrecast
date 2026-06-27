<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\NodeVisitor;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonRemoval;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonTraversalResult;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonTraverser;
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
        $document = (new JsonParser())->parse('{"name":"old"}');

        $result = $this->traverse($document, new class extends NodeJsonVisitorAbstract {
        });

        self::assertFalse($result->changeSet->isChanged($document));
    }

    public function testReturnedReplacementNodeIsRecordedAsChanged(): void
    {
        $document = (new JsonParser())->parse('{"name":"old"}');
        $visitor  = new class extends NodeJsonVisitorAbstract {
            public ?StringNode $replacement = null;

            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if (! $node instanceof StringNode || $node->value !== 'old') {
                    return null;
                }

                return $this->replacement = new StringNode('new');
            }
        };

        $result = $this->traverse($document, $visitor);

        self::assertInstanceOf(StringNode::class, $visitor->replacement);
        self::assertTrue($result->changeSet->isChanged($visitor->replacement));
    }

    public function testReturningSameNodeRecordsChange(): void
    {
        $document = (new JsonParser())->parse('{"name":"boundwize/jsonrecast"}');
        self::assertInstanceOf(ObjectNode::class, $document->value);
        $objectNode = $document->value;

        $result = $this->traverse($document, new class extends NodeJsonVisitorAbstract {
            public function leaveNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if (! $node instanceof ObjectNode || ! $path->isRoot()) {
                    return null;
                }

                $node->set('license', JsonValue::from('MIT'));

                return $node;
            }
        });

        self::assertTrue($result->changeSet->isChanged($objectNode));
    }

    public function testNoHasChangedAttributeExists(): void
    {
        self::assertFalse((new ReflectionClass(NodeAttributes::class))->hasConstant('HAS_CHANGED'));
    }

    public function testItReplacesStringNode(): void
    {
        $result = $this->replaceStringValue((new JsonParser())->parse('{"name":"old"}'), 'old', 'new');

        self::assertSame("{\n    \"name\": \"new\"\n}", (new JsonPrettyPrinter())->print($result->node));
    }

    public function testItUpdatesObjectItemValue(): void
    {
        $document = (new JsonParser())->parse('{"name":"old"}');

        $result = $this->traverse($document, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if (! $node instanceof ObjectItemNode || $node->key->value !== 'name') {
                    return null;
                }

                $node->value = new StringNode('new');

                return $node;
            }
        });

        self::assertSame("{\n    \"name\": \"new\"\n}", (new JsonPrettyPrinter())->print($result->node));
    }

    public function testItRemovesObjectItem(): void
    {
        $document = (new JsonParser())->parse('{"name":"boundwize/jsonrecast","minimum-stability":"dev"}');

        $result = $this->traverse($document, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJsonRemoval
            {
                if (! $node instanceof ObjectItemNode || $node->key->value !== 'minimum-stability') {
                    return null;
                }

                return NodeJsonRemoval::remove();
            }
        });

        self::assertSame(
            "{\n    \"name\": \"boundwize/jsonrecast\"\n}",
            (new JsonPrettyPrinter())->print($result->node),
        );
    }

    public function testItRemovesArrayItem(): void
    {
        $document = (new JsonParser())->parse('["json","temporary"]');

        $result = $this->traverse($document, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJsonRemoval
            {
                if (! $node instanceof ArrayItemNode || ! $node->value instanceof StringNode) {
                    return null;
                }

                if ($node->value->value !== 'temporary') {
                    return null;
                }

                return NodeJsonRemoval::remove();
            }
        });

        self::assertSame("[\n    \"json\"\n]", (new JsonPrettyPrinter())->print($result->node));
    }

    public function testItForbidsRemovingStringValueDirectly(): void
    {
        $document = (new JsonParser())->parse('{"name":"old"}');

        $this->expectException(LogicException::class);

        $this->traverse($document, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJsonRemoval
            {
                if (! $node instanceof StringNode || $node->value !== 'old') {
                    return null;
                }

                return NodeJsonRemoval::remove();
            }
        });
    }

    public function testItPassesPathToObjectValue(): void
    {
        $document = (new JsonParser())->parse('{"name":"old"}');
        $visitor  = new class extends NodeJsonVisitorAbstract {
            public bool $sawNameValue = false;

            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if ($node instanceof StringNode && $node->value === 'old' && $path->isObjectValue('name')) {
                    $this->sawNameValue = true;
                }

                return null;
            }
        };

        $this->traverse($document, $visitor);

        self::assertTrue($visitor->sawNameValue);
    }

    public function testItPassesPathToArrayValue(): void
    {
        $document = (new JsonParser())->parse('["a", "b"]');
        $visitor  = new class extends NodeJsonVisitorAbstract {
            /** @var list<int> */
            public array $indexes = [];

            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                $last = $path->last();

                if (
                    $node instanceof StringNode
                    && $last !== null
                    && is_int($last->value)
                    && ($path->isArrayValue(0) || $path->isArrayValue(1))
                ) {
                    $this->indexes[] = $last->value;
                }

                return null;
            }
        };

        $this->traverse($document, $visitor);

        self::assertSame([0, 1], $visitor->indexes);
    }

    public function testItPassesNestedMixedPath(): void
    {
        $document = (new JsonParser())->parse('{"items":[{"name":"first"}]}');
        $visitor  = new class extends NodeJsonVisitorAbstract {
            public bool $sawPath = false;

            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if (
                    $node instanceof StringNode
                    && $node->value === 'first'
                    && $path->matches(['items', 0, 'name'])
                ) {
                    $this->sawPath = true;
                }

                return null;
            }
        };

        $this->traverse($document, $visitor);

        self::assertTrue($visitor->sawPath);
    }

    public function testItTraversesRecursiveObjects(): void
    {
        $result = $this->replaceStringValue(
            (new JsonParser())->parse('{"a":{"b":{"c":"old"}}}'),
            'old',
            'new',
        );

        self::assertSame(
            "{\n    \"a\": {\n        \"b\": {\n            \"c\": \"new\"\n        }\n    }\n}",
            (new JsonPrettyPrinter())->print($result->node),
        );
    }

    public function testItTraversesRecursiveArrays(): void
    {
        $result = $this->replaceStringValue((new JsonParser())->parse('[[["old"]]]'), 'old', 'new');

        self::assertSame(
            "[\n    [\n        [\n            \"new\"\n        ]\n    ]\n]",
            (new JsonPrettyPrinter())->print($result->node),
        );
    }

    public function testItTraversesMixedRecursiveArraysAndObjects(): void
    {
        $result = $this->replaceStringValue(
            (new JsonParser())->parse('{"items":[{"values":["old"]}]}'),
            'old',
            'new',
        );

        self::assertSame(
            "{\n"
                . "    \"items\": [\n"
                . "        {\n"
                . "            \"values\": [\n"
                . "                \"new\"\n"
                . "            ]\n"
                . "        }\n"
                . "    ]\n"
                . '}',
            (new JsonPrettyPrinter())->print($result->node),
        );
    }

    public function testObjectNodeSetUpdatesExistingKey(): void
    {
        $result = $this->mutateRootObject('{"license":"GPL"}', static function (ObjectNode $objectNode): void {
            $objectNode->set('license', JsonValue::from('MIT'));
        });

        self::assertSame("{\n    \"license\": \"MIT\"\n}", (new JsonPrettyPrinter())->print($result->node));
    }

    public function testObjectNodeSetAddsMissingKey(): void
    {
        $result = $this->mutateRootObject(
            '{"name":"boundwize/jsonrecast"}',
            static function (ObjectNode $objectNode): void {
                $objectNode->set('license', JsonValue::from('MIT'));
            },
        );

        self::assertSame(
            "{\n    \"name\": \"boundwize/jsonrecast\",\n    \"license\": \"MIT\"\n}",
            (new JsonPrettyPrinter())->print($result->node),
        );
    }

    public function testObjectNodeRemoveRemovesKey(): void
    {
        $result = $this->mutateRootObject(
            '{"name":"boundwize/jsonrecast","minimum-stability":"dev"}',
            static function (ObjectNode $objectNode): void {
                $objectNode->remove('minimum-stability');
            },
        );

        self::assertSame(
            "{\n    \"name\": \"boundwize/jsonrecast\"\n}",
            (new JsonPrettyPrinter())->print($result->node),
        );
    }

    public function testArrayNodeAppendAddsValue(): void
    {
        $result = $this->mutateRootArray('["json"]', static function (ArrayNode $arrayNode): void {
            $arrayNode->append(JsonValue::from('ast'));
        });

        self::assertSame("[\n    \"json\",\n    \"ast\"\n]", (new JsonPrettyPrinter())->print($result->node));
    }

    public function testArrayNodeInsertAddsValueAtIndex(): void
    {
        $result = $this->mutateRootArray('["json","parser"]', static function (ArrayNode $arrayNode): void {
            $arrayNode->insert(1, JsonValue::from('ast'));
        });

        self::assertSame(
            "[\n    \"json\",\n    \"ast\",\n    \"parser\"\n]",
            (new JsonPrettyPrinter())->print($result->node),
        );
    }

    public function testArrayNodeRemoveAtRemovesValue(): void
    {
        $result = $this->mutateRootArray(
            '["json","temporary"]',
            static function (ArrayNode $arrayNode): void {
                $arrayNode->removeAt(1);
            },
        );

        self::assertSame("[\n    \"json\"\n]", (new JsonPrettyPrinter())->print($result->node));
    }

    public function testMutationWithoutReturnIsNotTracked(): void
    {
        $document = (new JsonParser())->parse('{"name":"boundwize/jsonrecast"}');
        self::assertInstanceOf(ObjectNode::class, $document->value);
        $objectNode = $document->value;

        $result = $this->traverse($document, new class extends NodeJsonVisitorAbstract {
            public function leaveNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if ($node instanceof ObjectNode && $path->isRoot()) {
                    $node->set('license', JsonValue::from('MIT'));
                }

                return null;
            }
        });

        self::assertFalse($result->changeSet->isChanged($objectNode));
    }

    private function replaceStringValue(NodeJson $document, string $old, string $new): NodeJsonTraversalResult
    {
        return $this->traverse($document, new class ($old, $new) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly string $old,
                private readonly string $new,
            ) {
            }

            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if (! $node instanceof StringNode || $node->value !== $this->old) {
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
        $mutation = Closure::fromCallable($mutation);
        $document = (new JsonParser())->parse($source);

        return $this->traverse($document, new class ($mutation) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly Closure $mutation,
            ) {
            }

            public function leaveNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if (! $node instanceof ObjectNode || ! $path->isRoot()) {
                    return null;
                }

                ($this->mutation)($node);

                return $node;
            }
        });
    }

    /**
     * @param callable(ArrayNode): void $mutation
     */
    private function mutateRootArray(string $source, callable $mutation): NodeJsonTraversalResult
    {
        $mutation = Closure::fromCallable($mutation);
        $document = (new JsonParser())->parse($source);

        return $this->traverse($document, new class ($mutation) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly Closure $mutation,
            ) {
            }

            public function leaveNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if (! $node instanceof ArrayNode || ! $path->isRoot()) {
                    return null;
                }

                ($this->mutation)($node);

                return $node;
            }
        });
    }

    private function traverse(NodeJson $node, NodeJsonVisitorAbstract $visitor): NodeJsonTraversalResult
    {
        $traverser = new NodeJsonTraverser();
        $traverser->addVisitor($visitor);

        return $traverser->traverse($node);
    }
}
