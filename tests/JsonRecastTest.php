<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests;

use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\JsonRecast\Parser\ParseError;
use Boundwize\JsonRecast\Value\JsonValue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_reverse;
use function count;
use function json_decode;

use const JSON_THROW_ON_ERROR;

final class JsonRecastTest extends TestCase
{
    public function testReadmeExampleAddsEditsDeletesAndRemovesEmptyParent(): void
    {
        $json = <<<'JSON'
{
    "name": "acme/demo",
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "autoload-dev": {
        "classmap": [
            "tests/Fixtures/App"
        ]
    },
    "minimum-stability": "dev"
}
JSON;

        $jsonDocument = JsonRecast::parse($json);

        $jsonRecastResult = JsonRecast::traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|NodeJson|int
            {
                if ($nodeJson instanceof ObjectItemNode && $nodeJsonPath->isRoot()) {
                    if ($nodeJson->key->value === 'name') {
                        $nodeJson->value = new StringNode('boundwize/jsonrecast');

                        return $nodeJson;
                    }

                    if ($nodeJson->key->value === 'minimum-stability') {
                        return NodeJsonVisitor::REMOVE_NODE;
                    }
                }

                if ($nodeJson instanceof ObjectNode && $nodeJsonPath->matches(['autoload', 'psr-4'])) {
                    $nodeJson->set('Boundwize\\JsonRecast\\', new StringNode('src/'));

                    return $nodeJson;
                }

                if ($nodeJson instanceof ArrayNode && $nodeJsonPath->matches(['autoload-dev', 'classmap'])) {
                    $removed = false;

                    foreach ($nodeJson->items as $index => $item) {
                        if (
                            ! $item->value instanceof StringNode
                            || $item->value->value !== 'tests/Fixtures/App'
                        ) {
                            continue;
                        }

                        $nodeJson->removeAt($index);
                        $removed = true;
                    }

                    return $removed ? $nodeJson : null;
                }

                return null;
            }

            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if (
                    ! $nodeJson instanceof ObjectItemNode
                    || ! $nodeJsonPath->isRoot()
                    || $nodeJson->key->value !== 'autoload-dev'
                    || ! $nodeJson->value instanceof ObjectNode
                ) {
                    return null;
                }

                $classmapItem = $nodeJson->value->get('classmap');

                if (
                    ! $classmapItem instanceof ObjectItemNode
                    || ! $classmapItem->value instanceof ArrayNode
                    || $classmapItem->value->items !== []
                ) {
                    return null;
                }

                return NodeJsonVisitor::REMOVE_NODE;
            }
        });

        $this->assertSame(<<<'JSON'
{
    "name": "boundwize/jsonrecast",
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Boundwize\\JsonRecast\\": "src/"
        }
    }
}
JSON, JsonRecast::print($jsonRecastResult));
    }

    public function testTraverseRequiresDocumentResult(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JsonRecast traversal must return JsonDocument.');

        JsonRecast::traverse(JsonRecast::parse('"old"'), new class extends NodeJsonVisitorAbstract {
            public function beforeTraverse(NodeJson $nodeJson): NodeJson
            {
                return new StringNode('new');
            }
        });
    }

    public function testParseAcceptsCustomMaximumDepth(): void
    {
        $this->expectException(ParseError::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        JsonRecast::parse('[[1]]', maximumDepth: 2);
    }

    public function testPrintAcceptsCustomMaximumDepth(): void
    {
        $jsonDocument = JsonRecast::parse('[[1]]', maximumDepth: 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        JsonRecast::print($jsonDocument, maximumDepth: 2);
    }

    public function testChangedDocumentPreservesRootWhitespace(): void
    {
        $jsonRecastResult = JsonRecast::traverse(
            JsonRecast::parse(" \n{\"name\": \"old\"}\n "),
            new class extends NodeJsonVisitorAbstract {
                public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if ($nodeJson instanceof StringNode && $nodeJson->value === 'old') {
                        return new StringNode('new');
                    }

                    return null;
                }
            },
        );

        $this->assertSame(" \n{\"name\": \"new\"}\n ", JsonRecast::print($jsonRecastResult));
    }

    public function testScalarEditPreservesUntouchedSiblingIndentStyle(): void
    {
        $source = "{\n\t\"t\": 1,\n  \"nest\": {\n    \"x\": 1\n  }\n}";

        $jsonRecastResult = JsonRecast::traverse(
            JsonRecast::parse($source),
            new class extends NodeJsonVisitorAbstract {
                public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if ($nodeJson instanceof NumberNode && $nodeJsonPath->isObjectValue('x')) {
                        return new NumberNode('999');
                    }

                    return null;
                }
            },
        );

        $this->assertSame(
            "{\n\t\"t\": 1,\n  \"nest\": {\n    \"x\": 999\n  }\n}",
            JsonRecast::print($jsonRecastResult),
        );
    }

    public function testScalarEditPreservesUntouchedMisalignedSiblingIndent(): void
    {
        $source = "{\n        \"deep\": 1,\n  \"nest\": {\n    \"x\": 1\n  }\n}";

        $jsonRecastResult = JsonRecast::traverse(
            JsonRecast::parse($source),
            new class extends NodeJsonVisitorAbstract {
                public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if ($nodeJson instanceof NumberNode && $nodeJsonPath->isObjectValue('x')) {
                        return new NumberNode('999');
                    }

                    return null;
                }
            },
        );

        $this->assertSame(
            "{\n        \"deep\": 1,\n  \"nest\": {\n    \"x\": 999\n  }\n}",
            JsonRecast::print($jsonRecastResult),
        );
    }

    public function testDocumentReplacementPreservesRootTrailingWhitespace(): void
    {
        $jsonRecastResult = JsonRecast::traverse(
            JsonRecast::parse("0\n\n"),
            new class extends NodeJsonVisitorAbstract {
                public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if (! $nodeJson instanceof JsonDocument || ! $nodeJsonPath->isRoot()) {
                        return null;
                    }

                    return new JsonDocument(new NumberNode('1'));
                }
            },
        );

        $this->assertSame("1\n\n", JsonRecast::print($jsonRecastResult));
    }

    public function testItPrintsArrayItemReplacementFromParsedNode(): void
    {
        $jsonRecastResult = JsonRecast::traverse(
            JsonRecast::parse('["old","new"]'),
            new class extends NodeJsonVisitorAbstract {
                public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if (! $nodeJson instanceof ArrayNode || ! $nodeJsonPath->isRoot()) {
                        return null;
                    }

                    $nodeJson->items[0]->value = $nodeJson->items[1]->value;

                    return $nodeJson;
                }
            },
        );

        $this->assertSame('["new","new"]', JsonRecast::print($jsonRecastResult));
    }

    public function testItPrintsObjectItemValueReplacementFromParsedNode(): void
    {
        $jsonRecastResult = JsonRecast::traverse(
            JsonRecast::parse('{"a":"old","b":"new"}'),
            new class extends NodeJsonVisitorAbstract {
                public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if (! $nodeJson instanceof ObjectNode || ! $nodeJsonPath->isRoot()) {
                        return null;
                    }

                    $nodeJson->items[0]->value = $nodeJson->items[1]->value;

                    return $nodeJson;
                }
            },
        );

        $this->assertSame('{"a":"new","b":"new"}', JsonRecast::print($jsonRecastResult));
    }

    public function testItPreservesInlineFormattingWhenArrayItemNodeIsReplaced(): void
    {
        $jsonRecastResult = JsonRecast::traverse(
            JsonRecast::parse('["a", "b"]'),
            new class extends NodeJsonVisitorAbstract {
                public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if (! $nodeJson instanceof ArrayItemNode || ! $nodeJsonPath->isArrayValue(0)) {
                        return null;
                    }

                    return new ArrayItemNode(new StringNode('x'));
                }
            },
        );

        $this->assertSame('["x", "b"]', JsonRecast::print($jsonRecastResult));
    }

    public function testItPreservesInlineFormattingWhenObjectItemNodeIsReplaced(): void
    {
        $jsonRecastResult = JsonRecast::traverse(
            JsonRecast::parse('{"a": 1, "b": 2}'),
            new class extends NodeJsonVisitorAbstract {
                public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if (! $nodeJson instanceof ObjectItemNode || $nodeJson->key->value !== 'a') {
                        return null;
                    }

                    return new ObjectItemNode(new StringNode('x'), new StringNode('y'));
                }
            },
        );

        $this->assertSame('{"x": "y", "b": 2}', JsonRecast::print($jsonRecastResult));
    }

    public function testItDoesNotCarryParsedArrayItemFramingIntoReplacedItemPosition(): void
    {
        $jsonDocument = JsonRecast::parse(
            <<<'JSON'
[
  9
]
JSON,
        );
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $replacementItem = $jsonDocument->value->items[0];

        $jsonRecastResult = JsonRecast::traverse(
            JsonRecast::parse('[1,2]'),
            new class ($replacementItem) extends NodeJsonVisitorAbstract {
                public function __construct(
                    private readonly ArrayItemNode $arrayItemNode,
                ) {
                }

                public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if (! $nodeJson instanceof ArrayItemNode || ! $nodeJsonPath->isArrayValue(0)) {
                        return null;
                    }

                    return $this->arrayItemNode;
                }
            },
        );

        $this->assertSame('[9,2]', JsonRecast::print($jsonRecastResult));
    }

    public function testItDoesNotCarryParsedObjectItemFramingIntoReplacedItemPosition(): void
    {
        $jsonDocument = JsonRecast::parse(
            <<<'JSON'
{
  "k" : 9
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $replacementItem = $jsonDocument->value->items[0];

        $jsonRecastResult = JsonRecast::traverse(
            JsonRecast::parse('{"a":1,"b":2}'),
            new class ($replacementItem) extends NodeJsonVisitorAbstract {
                public function __construct(
                    private readonly ObjectItemNode $objectItemNode,
                ) {
                }

                public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if (! $nodeJson instanceof ObjectItemNode || $nodeJson->key->value !== 'a') {
                        return null;
                    }

                    return $this->objectItemNode;
                }
            },
        );

        $this->assertSame('{"k":9,"b":2}', JsonRecast::print($jsonRecastResult));
    }

    public function testObjectNodeSetUpdatesEffectiveDuplicateKeyValue(): void
    {
        $jsonDocument = JsonRecast::parse('{"a":1,"a":2}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->set('a', new StringNode('changed'));

        $printed = JsonRecast::print($jsonDocument);

        $this->assertSame('{"a":"changed"}', $printed);
        $this->assertSame(['a' => 'changed'], json_decode($printed, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testObjectNodeSetAddsMissingKeyWithoutReformattingInlineDocument(): void
    {
        $jsonDocument = JsonRecast::parse('{"existing": "value"}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->set('newkey', new StringNode('newvalue'));

        $this->assertSame('{"existing": "value", "newkey": "newvalue"}', JsonRecast::print($jsonDocument));
    }

    public function testArrayNodeAppendAddsItemWithoutReformattingInlineDocument(): void
    {
        $jsonDocument = JsonRecast::parse('["existing"]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->append(new StringNode('item'));

        $this->assertSame('["existing", "item"]', JsonRecast::print($jsonDocument));
    }

    public function testArrayNodeInsertAddsItemWithoutReformattingInlineDocument(): void
    {
        $jsonDocument = JsonRecast::parse('["json", "parser"]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->insert(1, new StringNode('ast'));

        $this->assertSame('["json", "ast", "parser"]', JsonRecast::print($jsonDocument));
    }

    public function testItPrintsNewNestedArrayWithDetectedDocumentIndentation(): void
    {
        $jsonDocument = JsonRecast::parse(<<<'JSON'
{
  "a": 1,
  "b": [
    1,
    2
  ]
}
JSON);
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->set('b', JsonValue::from([10, 20, 30]));

        $this->assertSame(
            <<<'JSON'
{
  "a": 1,
  "b": [
    10,
    20,
    30
  ]
}
JSON,
            JsonRecast::print($jsonDocument),
        );
    }

    public function testItPrintsNewNestedObjectWithDetectedTabDocumentIndentation(): void
    {
        $jsonDocument = JsonRecast::parse("{\n\t\"a\": 1,\n\t\"c\": {\n\t\t\"old\": true\n\t}\n}");
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->set('c', JsonValue::from(['x' => 1, 'y' => 2]));

        $this->assertSame(
            "{\n\t\"a\": 1,\n\t\"c\": {\n\t\t\"x\": 1,\n\t\t\"y\": 2\n\t}\n}",
            JsonRecast::print($jsonDocument),
        );
    }

    public function testObjectNodeRemoveDeletesEffectiveDuplicateKeyValue(): void
    {
        $jsonDocument = JsonRecast::parse('{"a":1,"b":2,"a":3}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('a');

        $printed = JsonRecast::print($jsonDocument);

        $this->assertSame('{"b":2}', $printed);
        $this->assertSame(['b' => 2], json_decode($printed, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testArrayNodeRemoveAtPrintsDirectParsedMutation(): void
    {
        $jsonDocument = JsonRecast::parse('[1, 2, 3]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $this->assertTrue($jsonDocument->value->removeAt(1));

        $printed = JsonRecast::print($jsonDocument);

        $this->assertSame('[1, 3]', $printed);
        $this->assertSame([1, 3], json_decode($printed, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testMovingMultilineArrayItemIntoInlineEmptyArrayKeepsReadableFormatting(): void
    {
        $jsonDocument = JsonRecast::parse(
            <<<'JSON'
{
  "a": [
    {
      "x": 1
    }
  ],
  "b": []
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $aItem = $jsonDocument->value->get('a');
        $bItem = $jsonDocument->value->get('b');

        $this->assertInstanceOf(ObjectItemNode::class, $aItem);
        $this->assertInstanceOf(ObjectItemNode::class, $bItem);
        $this->assertInstanceOf(ArrayNode::class, $aItem->value);
        $this->assertInstanceOf(ArrayNode::class, $bItem->value);

        $node = $aItem->value->items[0]->value;

        $aItem->value->removeAt(0);
        $bItem->value->append($node);

        $this->assertSame(
            <<<'JSON'
{
  "a": [
  ],
  "b": [
    {
      "x": 1
    }
  ]
}
JSON,
            JsonRecast::print($jsonDocument),
        );
    }

    public function testAppendingAfterMovedMultilineArrayItemIntoInlineEmptyArrayKeepsReadableFormatting(): void
    {
        $jsonDocument = JsonRecast::parse(
            <<<'JSON'
{
  "a": [
    {
      "x": 1
    }
  ],
  "b": []
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $aItem = $jsonDocument->value->get('a');
        $bItem = $jsonDocument->value->get('b');

        $this->assertInstanceOf(ObjectItemNode::class, $aItem);
        $this->assertInstanceOf(ObjectItemNode::class, $bItem);
        $this->assertInstanceOf(ArrayNode::class, $aItem->value);
        $this->assertInstanceOf(ArrayNode::class, $bItem->value);

        $node = $aItem->value->items[0]->value;

        $aItem->value->removeAt(0);
        $bItem->value->append($node);
        $bItem->value->append(new StringNode('tail'));

        $this->assertSame(
            <<<'JSON'
{
  "a": [
  ],
  "b": [
    {
      "x": 1
    },
    "tail"
  ]
}
JSON,
            JsonRecast::print($jsonDocument),
        );
    }

    public function testMovingStringWithEscapedNewlineIntoInlineEmptyArrayStaysInline(): void
    {
        $jsonDocument = JsonRecast::parse(
            <<<'JSON'
{
  "a": ["hello\nworld"],
  "b": []
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $aItem = $jsonDocument->value->get('a');
        $bItem = $jsonDocument->value->get('b');

        $this->assertInstanceOf(ObjectItemNode::class, $aItem);
        $this->assertInstanceOf(ObjectItemNode::class, $bItem);
        $this->assertInstanceOf(ArrayNode::class, $aItem->value);
        $this->assertInstanceOf(ArrayNode::class, $bItem->value);

        $node = $aItem->value->items[0]->value;

        $aItem->value->removeAt(0);
        $bItem->value->append($node);

        $this->assertSame(
            <<<'JSON'
{
  "a": [],
  "b": ["hello\nworld"]
}
JSON,
            JsonRecast::print($jsonDocument),
        );
    }

    public function testItPrintsDirectParsedStringNodeValueMutation(): void
    {
        $jsonDocument = JsonRecast::parse('{"name":"old"}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $objectItem = $jsonDocument->value->get('name');
        $this->assertInstanceOf(ObjectItemNode::class, $objectItem);
        $this->assertInstanceOf(StringNode::class, $objectItem->value);
        $objectItem->value->value = 'new';

        $this->assertSame('{"name":"new"}', JsonRecast::print($jsonDocument));
    }

    public function testItPrintsDirectArrayItemReordering(): void
    {
        $jsonDocument = JsonRecast::parse('[1,2]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->items = array_reverse($jsonDocument->value->items);

        $printed = JsonRecast::print($jsonDocument);

        $this->assertSame('[2,1]', $printed);
        $this->assertSame([2, 1], json_decode($printed, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testItPrintsDirectObjectItemReordering(): void
    {
        $jsonDocument = JsonRecast::parse('{"a":1,"b":2}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->items = array_reverse($jsonDocument->value->items);

        $printed = JsonRecast::print($jsonDocument);

        $this->assertSame('{"b":2,"a":1}', $printed);
        $this->assertSame(['b' => 2, 'a' => 1], json_decode($printed, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testUntouchedNegativeZeroIsPreservedVerbatim(): void
    {
        $json = <<<'JSON'
        {
            "temperature_delta": -0,
            "label": "no change"
        }
        JSON;

        $jsonDocument = JsonRecast::parse($json);
        $printed      = JsonRecast::print($jsonDocument);

        $this->assertStringContainsString('"temperature_delta": -0', $printed);
    }

    public function testVisitorRebuiltNegativeZeroPreservesSignOnPrint(): void
    {
        $json = <<<'JSON'
        {
            "temperature_delta": -0,
            "label": "no change"
        }
        JSON;

        $jsonDocument = JsonRecast::parse($json);

        $jsonRecastResult = JsonRecast::traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof NumberNode) {
                    return null;
                }

                // Simulates any "read value, rebuild node" visitor --
                // e.g. rounding, scaling, or clamping a numeric field.
                $value = $nodeJson->toIntOrFloat() * 1;

                return new NumberNode((string) $value);
            }
        });

        $printed = JsonRecast::print($jsonRecastResult);

        $this->assertStringContainsString(
            '"temperature_delta": -0',
            $printed,
        );
    }

    public function testAppendedGeneratedObjectKeepsDefaultColonSpacingAfterSecondMutation(): void
    {
        $jsonDocument = JsonRecast::parse(<<<'JSON'
{
    "repositories": [
        {"type": "vcs", "url": "https://example.com/old.git"},
        {"type": "path", "url": "packages/local"}
    ]
}
JSON);

        $jsonRecastResult = JsonRecast::traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            private ?int $composerRepositoryIndex = null;

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof ArrayNode || ! $nodeJsonPath->matchesObjectKeys(['repositories'])) {
                    return null;
                }

                $nodeJson->removeAt(0);
                $this->composerRepositoryIndex = count($nodeJson->items);
                $nodeJson->append(JsonValue::from([
                    'type' => 'composer',
                    'url'  => 'https://repo.packagist.org',
                ]));

                return $nodeJson;
            }

            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (
                    $this->composerRepositoryIndex === null
                    || ! $nodeJson instanceof ObjectNode
                    || ! $nodeJsonPath->matches(['repositories', $this->composerRepositoryIndex])
                ) {
                    return null;
                }

                $canonical = $nodeJson->get('canonical');

                if (
                    $canonical instanceof ObjectItemNode
                    && $canonical->value instanceof BooleanNode
                    && $canonical->value->value === false
                ) {
                    return null;
                }

                $nodeJson->set('canonical', JsonValue::from(false));

                return $nodeJson;
            }
        });

        $this->assertSame(
            <<<'JSON'
{
    "repositories": [
        {"type": "path", "url": "packages/local"},
        {
            "type": "composer",
            "url": "https://repo.packagist.org",
            "canonical": false
        }
    ]
}
JSON,
            JsonRecast::print($jsonRecastResult),
        );
    }

    public function testObjectNodeSetFormatsFirstItemAddedToParsedEmptyMultilineObject(): void
    {
        $jsonDocument = JsonRecast::parse("{\n}");
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->set('name', new StringNode('jsonrecast'));

        $this->assertSame(
            "{\n    \"name\": \"jsonrecast\"\n}",
            JsonRecast::print($jsonDocument),
        );
    }

    public function testArrayNodeAppendFormatsFirstItemAddedToParsedEmptyMultilineArray(): void
    {
        $jsonDocument = JsonRecast::parse("[\n]");
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->append(new StringNode('jsonrecast'));

        $this->assertSame(
            "[\n    \"jsonrecast\"\n]",
            JsonRecast::print($jsonDocument),
        );
    }
}
