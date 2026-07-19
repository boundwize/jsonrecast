<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Printer;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeTraverser\NodeChangeSet;
use Boundwize\JsonRecast\Parser\JsonParser;
use Boundwize\JsonRecast\Printer\JsonPreservingPrinter;
use Boundwize\JsonRecast\Value\JsonValue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function array_reverse;

use const PHP_FLOAT_EPSILON;

final class JsonPreservingPrinterTest extends TestCase
{
    public function testItPrintsNewScalarNodes(): void
    {
        $jsonPreservingPrinter = new JsonPreservingPrinter();

        $this->assertSame('1', $jsonPreservingPrinter->print(new NumberNode('1')));
        $this->assertSame('true', $jsonPreservingPrinter->print(new BooleanNode(true)));
        $this->assertSame('false', $jsonPreservingPrinter->print(new BooleanNode(false)));
        $this->assertSame('null', $jsonPreservingPrinter->print(new NullNode()));
    }

    public function testItPrintsNewEmptyCollections(): void
    {
        $jsonPreservingPrinter = new JsonPreservingPrinter();

        $this->assertSame('{}', $jsonPreservingPrinter->print(new ObjectNode([])));
        $this->assertSame('[]', $jsonPreservingPrinter->print(new ArrayNode([])));
    }

    public function testItPrettyPrintsContainerWithNewItem(): void
    {
        $objectNode = new ObjectNode([
            new ObjectItemNode(new StringNode('name'), new StringNode('jsonrecast')),
        ]);
        $objectNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '{}');

        $this->assertSame(
            <<<'JSON'
{
    "name": "jsonrecast"
}
JSON,
            (new JsonPreservingPrinter())->print($objectNode),
        );
    }

    public function testItPrettyPrintsContainerWithMultipleNewItems(): void
    {
        $objectNode = new ObjectNode([
            new ObjectItemNode(new StringNode('name'), new StringNode('jsonrecast')),
            new ObjectItemNode(new StringNode('type'), new StringNode('library')),
        ]);
        $objectNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '{}');

        $this->assertSame(
            <<<'JSON'
{
    "name": "jsonrecast",
    "type": "library"
}
JSON,
            (new JsonPreservingPrinter())->print($objectNode),
        );
    }

    public function testItPrettyPrintsArrayWithNewItem(): void
    {
        $arrayNode = new ArrayNode([
            new ArrayItemNode(new StringNode('jsonrecast')),
        ]);
        $arrayNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '[]');

        $this->assertSame(
            <<<'JSON'
[
    "jsonrecast"
]
JSON,
            (new JsonPreservingPrinter())->print($arrayNode),
        );
    }

    public function testItPrettyPrintsArrayWithMultipleNewItems(): void
    {
        $arrayNode = new ArrayNode([
            new ArrayItemNode(new StringNode('json')),
            new ArrayItemNode(new StringNode('recast')),
        ]);
        $arrayNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '[]');

        $this->assertSame(
            <<<'JSON'
[
    "json",
    "recast"
]
JSON,
            (new JsonPreservingPrinter())->print($arrayNode),
        );
    }

    public function testItPreservesParsedArrayNode(): void
    {
        $jsonDocument = (new JsonParser())->parse('["json"]');

        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);
        $this->assertSame('["json"]', (new JsonPreservingPrinter())->print($jsonDocument->value));
    }

    public function testItUsesParsedNodeIndentWhenPrintingTabIndentedSubtreeDirectly(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            "{\n\t\"template\": {\n\t\t\"name\": \"json\"\n\t}\n}",
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $templateItem = $jsonDocument->value->get('template');
        $this->assertInstanceOf(ObjectItemNode::class, $templateItem);
        $this->assertInstanceOf(ObjectNode::class, $templateItem->value);

        $this->assertSame(
            "{\n\t\"name\": \"json\"\n}",
            (new JsonPreservingPrinter())->print($templateItem->value),
        );
    }

    public function testItUsesParsedNodeNewlineWhenPrintingChangedSubtreeDirectly(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            "{\r\n    \"template\":{\"name\":\"json\"}\r\n}",
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $templateItem = $jsonDocument->value->get('template');
        $this->assertInstanceOf(ObjectItemNode::class, $templateItem);
        $this->assertInstanceOf(ObjectNode::class, $templateItem->value);

        $templateItem->value->set('data', JsonValue::from(['x' => 1, 'y' => 2]));

        $this->assertSame(
            "{\r\n"
            . "    \"name\":\"json\",\r\n"
            . "    \"data\":{\r\n"
            . "        \"x\": 1,\r\n"
            . "        \"y\": 2\r\n"
            . "    }\r\n"
            . '}',
            (new JsonPreservingPrinter())->print($templateItem->value),
        );
    }

    public function testItPrettyPrintsInlineObjectWhenExistingValueIsReplacedByParsedMultilineValue(): void
    {
        $fragment = (new JsonParser())->parse(
            <<<'JSON'
{
    "x": 1,
    "y": 2
}
JSON,
        );

        $jsonDocument = (new JsonParser())->parse('{"a": 1, "b": 2}');
        $this->assertInstanceOf(ObjectNode::class, $fragment->value);
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->set('a', $fragment->value);

        $this->assertSame(
            <<<'JSON'
{
    "a": {
        "x": 1,
        "y": 2
    },
    "b": 2
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesParsedMultilineEmptyObjectNode(): void
    {
        $jsonDocument = (new JsonParser())->parse("{\n        }");

        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $this->assertSame("{\n        }", (new JsonPreservingPrinter())->print($jsonDocument->value));
    }

    public function testItPreservesParsedMultilineEmptyArrayNode(): void
    {
        $jsonDocument = (new JsonParser())->parse("[\n        ]");

        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);
        $this->assertSame("[\n        ]", (new JsonPreservingPrinter())->print($jsonDocument->value));
    }

    public function testItRejectsNodeThatExceedsMaximumNestingDepth(): void
    {
        $nodeJson = JsonValue::from([[0]], maximumDepth: 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        (new JsonPreservingPrinter(maximumDepth: 2))->print(new JsonDocument($nodeJson));
    }

    public function testMaximumNestingDepthCanBeOverridden(): void
    {
        $nodeJson = JsonValue::from([[0]], maximumDepth: 3);

        $this->assertSame(
            "[\n    [\n        0\n    ]\n]",
            (new JsonPreservingPrinter(maximumDepth: 3))->print(new JsonDocument($nodeJson)),
        );
    }

    public function testMaximumNestingDepthResetsForNextInlineSiblingAfterNestedStack(): void
    {
        $jsonDocument = (new JsonParser(maximumDepth: 3))->parse('{"1":[2],"2":2}');

        $this->assertSame(
            '{"1":[2],"2":2}',
            (new JsonPreservingPrinter(maximumDepth: 3))->print($jsonDocument),
        );
    }

    public function testMaximumNestingDepthMustBeGreaterThanZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum depth must be greater than 0.');

        new JsonPreservingPrinter(maximumDepth: 0);
    }

    public function testItPreservesTrailingNewlineWhenDocumentAfterValueIsEmpty(): void
    {
        $jsonDocument = new JsonDocument(new StringNode('json'));
        $jsonDocument->setAttribute(NodeAttributes::NEWLINE, "\r\n");
        $jsonDocument->setAttribute(NodeAttributes::TRAILING_NEWLINE, true);

        $this->assertSame("\"json\"\r\n", (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPreservesTrailingNewlineWhenDocumentAfterValueDoesNotEndWithNewline(): void
    {
        $jsonDocument = new JsonDocument(new StringNode('json'), afterValue: ' ');
        $jsonDocument->setAttribute(NodeAttributes::NEWLINE, "\n");
        $jsonDocument->setAttribute(NodeAttributes::TRAILING_NEWLINE, true);

        $this->assertSame("\"json\" \n", (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItDoesNotDuplicateMixedTrailingNewlineWhenDocumentIsChanged(): void
    {
        $jsonDocument = (new JsonParser())->parse("{\r\n    \"name\": \"old\"\r\n}\n");
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $nameItem = $jsonDocument->value->get('name');
        $this->assertInstanceOf(ObjectItemNode::class, $nameItem);
        $this->assertInstanceOf(StringNode::class, $nameItem->value);

        $nameItem->value->value = 'new';

        $this->assertSame(
            "{\r\n    \"name\": \"new\"\r\n}\n",
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesDocumentFramingWhitespaceAfterRootValueReplacement(): void
    {
        $jsonDocument        = (new JsonParser())->parse("\n1\t");
        $replacementDocument = (new JsonParser())->parse('1');

        $this->assertInstanceOf(NumberNode::class, $jsonDocument->value);
        $this->assertInstanceOf(NumberNode::class, $replacementDocument->value);

        $jsonDocument->value = $replacementDocument->value;

        $this->assertSame("\n1\t", (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPrintsNodeWithNonStringOriginalText(): void
    {
        $stringNode = new StringNode('json');
        $stringNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, 123);

        $this->assertSame('"json"', (new JsonPreservingPrinter())->print($stringNode));
    }

    public function testItPreservesOriginalTextWithoutDepthMetadata(): void
    {
        $numberNode = new NumberNode('1');
        $numberNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '1');

        $this->assertSame('1', (new JsonPreservingPrinter())->print($numberNode));
    }

    public function testItDoesNotReuseCommaWhitespaceWhenFirstInlineArrayItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('["first", "second"]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(0);

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('["second"]', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPreservesCommaWhitespaceWhenMiddleInlineArrayItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('["first", "second", "third"]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(1);

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('["first", "third"]', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPreservesCommaWhitespaceWhenLastInlineArrayItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('["first", "second", "third"]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(2);

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('["first", "second"]', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItDoesNotReuseCommaWhitespaceWhenLastInlineArrayItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('["first" , "second"]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(1);

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('["first"]', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItDoesNotReuseCommaWhitespaceWhenFirstInlineObjectItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"first": 1, "second": 2}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('first');

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('{"second": 2}', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPreservesCommaWhitespaceWhenMiddleInlineObjectItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"first": 1, "second": 2, "third": 3}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('second');

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame(
            '{"first": 1, "third": 3}',
            (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument),
        );
    }

    public function testItPreservesCommaWhitespaceWhenLastInlineObjectItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"first": 1, "second": 2, "third": 3}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('third');

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame(
            '{"first": 1, "second": 2}',
            (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument),
        );
    }

    public function testItDoesNotReuseCommaWhitespaceWhenLastInlineObjectItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"first": 1 , "second": 2}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('second');

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('{"first": 1}', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPreservesCommaWhitespaceWhenArrayItemsAreReordered(): void
    {
        $jsonDocument = (new JsonParser())->parse('[1, 2, 3]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->items = array_reverse($jsonDocument->value->items);

        $this->assertSame('[3, 2, 1]', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPreservesCommaWhitespaceWhenInlineArrayItemsAreReorderedAndNewItemIsAppended(): void
    {
        $jsonDocument = (new JsonParser())->parse('[1, 2, 3]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $items                      = $jsonDocument->value->items;
        $jsonDocument->value->items = [$items[2], $items[1], $items[0]];
        $jsonDocument->value->append(new NumberNode('4'));

        $this->assertSame('[3, 2, 1, 4]', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPreservesMultilineWhitespaceWhenArrayItemsAreReordered(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
[
    1,
    2,
    3
]
JSON,
        );
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->items = array_reverse($jsonDocument->value->items);

        $this->assertSame(
            <<<'JSON'
[
    3,
    2,
    1
]
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesMultilineWhitespaceWhenArrayItemsAreReorderedAndNewItemIsAppended(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
[
    1,
    2,
    3
]
JSON,
        );
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $items                      = $jsonDocument->value->items;
        $jsonDocument->value->items = [$items[2], $items[1], $items[0]];
        $jsonDocument->value->append(new NumberNode('4'));

        $this->assertSame(
            <<<'JSON'
[
    3,
    2,
    1,
    4
]
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesMultilineWhitespaceWhenArrayItemsAreInsertedBeforeParsedItems(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
[
    1,
    2
]
JSON,
        );
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->insert(0, new NumberNode('4'));
        $jsonDocument->value->insert(0, new NumberNode('3'));

        $this->assertSame(
            <<<'JSON'
[
    3,
    4,
    1,
    2
]
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesMultilineWhitespaceWhenArrayItemsAreRemovedAppendedAndReordered(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
[
    1,
    2
]
JSON,
        );
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(0);
        $jsonDocument->value->append(new NumberNode('3'));

        $items                      = $jsonDocument->value->items;
        $jsonDocument->value->items = [$items[1], $items[0]];

        $this->assertSame(
            <<<'JSON'
[
    3,
    2
]
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesInlineWhitespaceWhenChangedArrayItemsAreRemovedAppendedAndReordered(): void
    {
        $jsonDocument = (new JsonParser())->parse('[1, 2, 3]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(1);
        $jsonDocument->value->append(new NumberNode('9'));
        $jsonDocument->value->append(new NumberNode('8'));

        $items                      = $jsonDocument->value->items;
        $jsonDocument->value->items = [$items[2], $items[3], $items[0], $items[1]];

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame(
            '[9, 8, 1, 3]',
            (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument),
        );
    }

    public function testItDoesNotPrintClosingWhitespaceBeforeArraySeparatorsForSyntheticItems(): void
    {
        $items = [
            new ArrayItemNode(new NumberNode('1'), "\n    ", "\n"),
            new ArrayItemNode(new NumberNode('2'), "\n    ", "\n"),
            new ArrayItemNode(new NumberNode('3'), "\n    ", "\n"),
        ];

        foreach ($items as $item) {
            $item->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        }

        $arrayNode = new ArrayNode($items, "\n    ", "\n");
        $arrayNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '[]');

        $this->assertSame(
            <<<'JSON'
[
    1,
    2,
    3
]
JSON,
            (new JsonPreservingPrinter())->print($arrayNode),
        );
    }

    public function testItPrintsArrayItemsWithoutStartOffsets(): void
    {
        $first = new ArrayItemNode(new NumberNode('1'));
        $first->setAttribute(NodeAttributes::ORIGINAL_TEXT, '1');

        $second = new ArrayItemNode(new NumberNode('2'), ' ');
        $second->setAttribute(NodeAttributes::ORIGINAL_TEXT, ' 2');

        $arrayNode = new ArrayNode([$second, $first]);
        $arrayNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '[1, 2]');

        $this->assertSame('[2,1]', (new JsonPreservingPrinter())->print($arrayNode));
    }

    public function testItPreservesCommaWhitespaceWhenObjectItemsAreReordered(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"a":1, "b":2, "c":3}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->items = array_reverse($jsonDocument->value->items);

        $this->assertSame('{"c":3, "b":2, "a":1}', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPreservesCommaWhitespaceWhenInlineObjectItemsAreReorderedAndNewItemIsAppended(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"a": 1, "b": 2, "c": 3}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $items                      = $jsonDocument->value->items;
        $jsonDocument->value->items = [$items[2], $items[1], $items[0]];
        $jsonDocument->value->set('d', new NumberNode('4'));

        $this->assertSame('{"c": 3, "b": 2, "a": 1, "d": 4}', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPreservesInlineWhitespaceWhenObjectItemsAreReordered(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"a" : 1,"b":2 , "c" : 3}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->items = array_reverse($jsonDocument->value->items);

        $this->assertSame('{"c" : 3,"b":2 , "a" : 1}', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPreservesMultilineWhitespaceWhenObjectItemsAreReordered(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "a": 1,
    "b": 2,
    "c": 3
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->items = array_reverse($jsonDocument->value->items);

        $this->assertSame(
            <<<'JSON'
{
    "c": 3,
    "b": 2,
    "a": 1
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesMultilineWhitespaceWhenObjectItemsAreReorderedAndNewItemIsAdded(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "a": 1,
    "b": 2,
    "c": 3
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $items                      = $jsonDocument->value->items;
        $jsonDocument->value->items = [$items[2], $items[1], $items[0]];
        $jsonDocument->value->set('d', new NumberNode('4'));

        $this->assertSame(
            <<<'JSON'
{
    "c": 3,
    "b": 2,
    "a": 1,
    "d": 4
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesMultilineWhitespaceWhenObjectItemsAreRemovedAppendedAndReordered(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "a": 1,
    "b": 2
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('a');
        $jsonDocument->value->set('z', new NumberNode('3'));

        $items                      = $jsonDocument->value->items;
        $jsonDocument->value->items = [$items[1], $items[0]];

        $this->assertSame(
            <<<'JSON'
{
    "z": 3,
    "b": 2
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesInlineWhitespaceWhenChangedObjectItemsAreRemovedAppendedAndReordered(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"a": 1, "b": 2, "c": 3}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('b');
        $jsonDocument->value->set('x', new NumberNode('9'));
        $jsonDocument->value->set('y', new NumberNode('8'));

        $items                      = $jsonDocument->value->items;
        $jsonDocument->value->items = [$items[2], $items[3], $items[0], $items[1]];

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame(
            '{"x": 9, "y": 8, "a": 1, "c": 3}',
            (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument),
        );
    }

    public function testItPrettyPrintsInlineObjectWhenInsertedItemValueHasMultilineOriginalText(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "big": {
        "x": 1,
        "y": 2
    },
    "obj": {"p": 1, "q": 2}
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $bigItem = $jsonDocument->value->get('big');
        $objItem = $jsonDocument->value->get('obj');
        $this->assertInstanceOf(ObjectItemNode::class, $bigItem);
        $this->assertInstanceOf(ObjectItemNode::class, $objItem);
        $this->assertInstanceOf(ObjectNode::class, $objItem->value);

        $objItem->value->set('r', $bigItem->value);

        $this->assertSame(
            <<<'JSON'
{
    "big": {
        "x": 1,
        "y": 2
    },
    "obj": {
        "p": 1,
        "q": 2,
        "r": {
            "x": 1,
            "y": 2
        }
    }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPrettyPrintsRootInlineObjectWhenInsertedItemValueHasMultilineOriginalText(): void
    {
        $fragment     = (new JsonParser())->parse(
            <<<'JSON'
{
    "x": 1,
    "y": 2
}
JSON,
        );
        $jsonDocument = (new JsonParser())->parse('{"p": 1, "q": 2}');

        $this->assertInstanceOf(ObjectNode::class, $fragment->value);
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->set('r', $fragment->value);

        $this->assertSame(
            <<<'JSON'
{
    "p": 1,
    "q": 2,
    "r": {
        "x": 1,
        "y": 2
    }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPrettyPrintsSpacePaddedObjectWhenInsertedValueIsMultiline(): void
    {
        $fragment     = (new JsonParser())->parse(
            <<<'JSON'
{
    "x": 1,
    "y": 2
}
JSON,
        );
        $jsonDocument = (new JsonParser())->parse('{ "a": 1 }');

        $this->assertInstanceOf(ObjectNode::class, $fragment->value);
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->set('big', $fragment->value);

        $this->assertSame(
            <<<'JSON'
{
    "a": 1,
    "big": {
        "x": 1,
        "y": 2
    }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPrettyPrintsSpacePaddedArrayWhenAppendedValueIsMultiline(): void
    {
        $fragment     = (new JsonParser())->parse(
            <<<'JSON'
{
    "x": 1,
    "y": 2
}
JSON,
        );
        $jsonDocument = (new JsonParser())->parse('[ 1 ]');

        $this->assertInstanceOf(ObjectNode::class, $fragment->value);
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->append($fragment->value);

        $this->assertSame(
            <<<'JSON'
[
    1,
    {
        "x": 1,
        "y": 2
    }
]
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItReindentsGraftedSubtreeThatUsesDifferentIndentUnitAtSameDepth(): void
    {
        $fragment     = (new JsonParser())->parse(
            <<<'JSON'
{
  "source": {
    "a": 1,

    "b": 2
  }
}
JSON,
        );
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "outer": 1,
    "grafted": {}
}
JSON,
        );

        $this->assertInstanceOf(ObjectNode::class, $fragment->value);
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $sourceItem = $fragment->value->get('source');
        $this->assertInstanceOf(ObjectItemNode::class, $sourceItem);

        $jsonDocument->value->set('grafted', $sourceItem->value);

        $this->assertSame(
            <<<'JSON'
{
    "outer": 1,
    "grafted": {
        "a": 1,

        "b": 2
    }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItReindentsGraftedSubtreeWithInconsistentSourceIndentation(): void
    {
        $fragment     = (new JsonParser())->parse(
            <<<'JSON'
{
    "source": {
        "a": 1,
       "b": 2,
         "c": 3
    }
}
JSON,
        );
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
  "outer": 1,
  "grafted": {}
}
JSON,
        );

        $this->assertInstanceOf(ObjectNode::class, $fragment->value);
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $sourceItem = $fragment->value->get('source');
        $this->assertInstanceOf(ObjectItemNode::class, $sourceItem);

        $jsonDocument->value->set('grafted', $sourceItem->value);

        $this->assertSame(
            <<<'JSON'
{
  "outer": 1,
  "grafted": {
    "a": 1,
   "b": 2,
     "c": 3
  }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItScalesInconsistentIndentationWhenGraftingIntoTabIndentedDocument(): void
    {
        $fragment     = (new JsonParser())->parse(
            <<<'JSON'
{
    "source": {
        "a": 1,
      "b": 2,
        "c": 3
    }
}
JSON,
        );
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
	"outer": 1,
	"grafted": {}
}
JSON,
        );

        $this->assertInstanceOf(ObjectNode::class, $fragment->value);
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $sourceItem = $fragment->value->get('source');
        $this->assertInstanceOf(ObjectItemNode::class, $sourceItem);

        $jsonDocument->value->set('grafted', $sourceItem->value);

        $this->assertSame(
            <<<'JSON'
{
	"outer": 1,
	"grafted": {
		"a": 1,
	  "b": 2,
		"c": 3
	}
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItScalesInconsistentIndentationWhenGraftingIntoSpaceIndentedDocument(): void
    {
        $fragment     = (new JsonParser())->parse(
            <<<'JSON'
{
    "source": {
        "a": 1,
      "b": 2,
        "c": 3
    }
}
JSON,
        );
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
  "outer": 1,
  "grafted": {}
}
JSON,
        );

        $this->assertInstanceOf(ObjectNode::class, $fragment->value);
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $sourceItem = $fragment->value->get('source');
        $this->assertInstanceOf(ObjectItemNode::class, $sourceItem);

        $jsonDocument->value->set('grafted', $sourceItem->value);

        $this->assertSame(
            <<<'JSON'
{
  "outer": 1,
  "grafted": {
    "a": 1,
   "b": 2,
    "c": 3
  }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItScalesPositiveResidualIndentationWhenGraftingIntoSpaceIndentedDocument(): void
    {
        $fragment     = (new JsonParser())->parse(
            <<<'JSON'
{
    "source": {
        "a": 1,
         "b": 2,
        "c": 3
    }
}
JSON,
        );
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
  "outer": 1,
  "grafted": {}
}
JSON,
        );

        $this->assertInstanceOf(ObjectNode::class, $fragment->value);
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $sourceItem = $fragment->value->get('source');
        $this->assertInstanceOf(ObjectItemNode::class, $sourceItem);

        $jsonDocument->value->set('grafted', $sourceItem->value);

        $this->assertSame(
            <<<'JSON'
{
  "outer": 1,
  "grafted": {
    "a": 1,
     "b": 2,
    "c": 3
  }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItScalesInconsistentIndentationWhenGraftingIntoNestedSpaceIndentedDocument(): void
    {
        $fragment     = (new JsonParser())->parse(
            <<<'JSON'
{
    "source": {
        "a": 1,
      "b": 2,
        "c": 3
    }
}
JSON,
        );
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
  "wrapper": {
    "grafted": {}
  }
}
JSON,
        );

        $this->assertInstanceOf(ObjectNode::class, $fragment->value);
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $wrapperItem = $jsonDocument->value->get('wrapper');
        $this->assertInstanceOf(ObjectItemNode::class, $wrapperItem);
        $this->assertInstanceOf(ObjectNode::class, $wrapperItem->value);

        $sourceItem = $fragment->value->get('source');
        $this->assertInstanceOf(ObjectItemNode::class, $sourceItem);

        $wrapperItem->value->set('grafted', $sourceItem->value);

        $this->assertSame(
            <<<'JSON'
{
  "wrapper": {
    "grafted": {
      "a": 1,
     "b": 2,
      "c": 3
    }
  }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItReindentsChangedItemInsideGraftedObjectSubtree(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "k0": 0
}
JSON,
        );
        $fragment     = (new JsonParser())->parse(
            <<<'JSON'
{
  "lvl0": {
    "lvl1": 1
  },
  "other": 2
}
JSON,
        );

        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $this->assertInstanceOf(ObjectNode::class, $fragment->value);

        $jsonDocument->value->set('grafted', $fragment->value);
        $fragment->value->set('other', new NumberNode('999'));

        $this->assertSame(
            <<<'JSON'
{
    "k0": 0,
    "grafted": {
        "lvl0": {
            "lvl1": 1
        },
        "other": 999
    }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItReindentsChangedItemInsideGraftedArraySubtree(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "k0": 0
}
JSON,
        );
        $fragment     = (new JsonParser())->parse(
            <<<'JSON'
[
  [
    1
  ],
  2
]
JSON,
        );

        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $this->assertInstanceOf(ArrayNode::class, $fragment->value);

        $jsonDocument->value->set('grafted', $fragment->value);
        $fragment->value->setAt(1, new NumberNode('999'));

        $this->assertSame(
            <<<'JSON'
{
    "k0": 0,
    "grafted": [
        [
            1
        ],
        999
    ]
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPrettyPrintsInlineAncestorArrayWhenNestedMutationPrintsMultiline(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "big": {
        "x": 1,
        "y": 2
    },
    "outer": [[1, 2], [3, 4]]
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $bigItem   = $jsonDocument->value->get('big');
        $outerItem = $jsonDocument->value->get('outer');
        $this->assertInstanceOf(ObjectItemNode::class, $bigItem);
        $this->assertInstanceOf(ObjectItemNode::class, $outerItem);
        $this->assertInstanceOf(ArrayNode::class, $outerItem->value);

        $innerItem = $outerItem->value->items[0];
        $this->assertInstanceOf(ArrayNode::class, $innerItem->value);
        $innerItem->value->append($bigItem->value);

        $this->assertSame(
            <<<'JSON'
{
    "big": {
        "x": 1,
        "y": 2
    },
    "outer": [
        [
            1,
            2,
            {
                "x": 1,
                "y": 2
            }
        ],
        [3, 4]
    ]
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItDoesNotPrintClosingWhitespaceBeforeObjectSeparatorsForSyntheticItems(): void
    {
        $items = [
            new ObjectItemNode(new StringNode('x'), new NumberNode('1'), "\n    ", '', ' ', "\n"),
            new ObjectItemNode(new StringNode('y'), new NumberNode('2'), "\n    ", '', ' ', "\n"),
            new ObjectItemNode(new StringNode('z'), new NumberNode('3'), "\n    ", '', ' ', "\n"),
        ];

        foreach ($items as $item) {
            $item->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);
        }

        $objectNode = new ObjectNode($items, "\n    ", "\n");
        $objectNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '{}');

        $this->assertSame(
            <<<'JSON'
{
    "x": 1,
    "y": 2,
    "z": 3
}
JSON,
            (new JsonPreservingPrinter())->print($objectNode),
        );
    }

    public function testItReusesNextSeparatorWhitespaceForSyntheticObjectItems(): void
    {
        $synthetic = new ObjectItemNode(new StringNode('x'), new NumberNode('1'), '', '', '', "\n");
        $synthetic->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);

        $parsed = new ObjectItemNode(new StringNode('y'), new NumberNode('2'), ' ', '', '', ' ');
        $parsed->setAttribute(NodeAttributes::ORIGINAL_TEXT, '"y":2');

        $this->assertSame(
            ' ',
            $this->invokeJsonPreservingPrinterMethod(
                'normalizeSyntheticAfterValue',
                [[$synthetic, $parsed], 0, "\n", $synthetic, "\n"],
            ),
        );
    }

    public function testItReusesNextSeparatorWhitespaceForSyntheticArrayItems(): void
    {
        $synthetic = new ArrayItemNode(new NumberNode('1'), '', "\n");
        $synthetic->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);

        $parsed = new ArrayItemNode(new NumberNode('2'), ' ', ' ');
        $parsed->setAttribute(NodeAttributes::ORIGINAL_TEXT, '2');

        $this->assertSame(
            ' ',
            $this->invokeJsonPreservingPrinterMethod(
                'normalizeSyntheticAfterValue',
                [[$synthetic, $parsed], 0, "\n", $synthetic, "\n"],
            ),
        );
    }

    public function testItComputesSyntheticStartOffsetsForNeighborFallbacks(): void
    {
        $previous = new ArrayItemNode(new NumberNode('1'));
        $previous->setAttribute(NodeAttributes::START_OFFSET, 10);

        $next = new ArrayItemNode(new NumberNode('3'));
        $next->setAttribute(NodeAttributes::START_OFFSET, 20);

        $between    = new ArrayItemNode(new NumberNode('2'));
        $beforeOnly = new ArrayItemNode(new NumberNode('4'));
        $afterOnly  = new ArrayItemNode(new NumberNode('5'));

        $this->assertEqualsWithDelta(10.5, $this->invokeJsonPreservingPrinterMethod(
            'getSyntheticStartOffset',
            [[$previous, $between, $next], 1],
        ), PHP_FLOAT_EPSILON);

        $this->assertEqualsWithDelta(10.5, $this->invokeJsonPreservingPrinterMethod(
            'getSyntheticStartOffset',
            [[$previous, $beforeOnly], 1],
        ), PHP_FLOAT_EPSILON);

        $this->assertEqualsWithDelta(19.5, $this->invokeJsonPreservingPrinterMethod(
            'getSyntheticStartOffset',
            [[$afterOnly, $next], 0],
        ), PHP_FLOAT_EPSILON);
    }

    public function testItPrintsDirectStringNodeValueMutation(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"name":"old"}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $objectItem = $jsonDocument->value->get('name');
        $this->assertInstanceOf(ObjectItemNode::class, $objectItem);
        $this->assertInstanceOf(StringNode::class, $objectItem->value);
        $objectItem->value->value = 'new';

        $this->assertSame('{"name":"new"}', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPrintsNewStringNodeWithoutEscapingUnicode(): void
    {
        $city         = "M\xC3\xBCnchen";
        $note         = "Gr\xC3\xBC\xC3\x9Fe";
        $jsonDocument = (new JsonParser())->parse('{"city": "' . $city . '"}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->set('note', new StringNode($note));

        $this->assertSame(
            '{"city": "' . $city . '", "note": "' . $note . '"}',
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPrintsReplacementStringNodeWithoutEscapingUnicode(): void
    {
        $city         = "M\xC3\xBCnchen";
        $jsonDocument = (new JsonParser())->parse('{"city":"' . $city . '"}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $objectItem = $jsonDocument->value->get('city');
        $this->assertInstanceOf(ObjectItemNode::class, $objectItem);
        $objectItem->value = new StringNode($city);

        $this->assertSame('{"city":"' . $city . '"}', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPrintsDirectNumberNodeRawValueMutation(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"count":1}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $objectItem = $jsonDocument->value->get('count');
        $this->assertInstanceOf(ObjectItemNode::class, $objectItem);
        $this->assertInstanceOf(NumberNode::class, $objectItem->value);
        $objectItem->value->rawValue = '2';

        $this->assertSame('{"count":2}', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPrintsDirectObjectItemValueReplacementWithExistingParsedNode(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"from":1,"to":0}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $from = $jsonDocument->value->get('from');
        $to   = $jsonDocument->value->get('to');

        $this->assertInstanceOf(ObjectItemNode::class, $from);
        $this->assertInstanceOf(ObjectItemNode::class, $to);

        $to->value = $from->value;

        $this->assertSame('{"from":1,"to":1}', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPrintsDirectArrayItemValueReplacementWithExistingParsedNode(): void
    {
        $jsonDocument = (new JsonParser())->parse('[1,0]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->items[1]->value = $jsonDocument->value->items[0]->value;

        $this->assertSame('[1,1]', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPrintsDirectDocumentValueReplacementWithExistingParsedNode(): void
    {
        $jsonDocument        = (new JsonParser())->parse('0');
        $replacementDocument = (new JsonParser())->parse('1');

        $jsonDocument->value = $replacementDocument->value;

        $this->assertSame('1', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPrintsDirectBooleanNodeValueMutation(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"enabled":false}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $objectItem = $jsonDocument->value->get('enabled');
        $this->assertInstanceOf(ObjectItemNode::class, $objectItem);
        $this->assertInstanceOf(BooleanNode::class, $objectItem->value);
        $objectItem->value->value = true;

        $this->assertSame('{"enabled":true}', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPrintsDirectStringNodeValueMutationInArray(): void
    {
        $jsonDocument = (new JsonParser())->parse('["old"]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $arrayItem = $jsonDocument->value->items[0];
        $this->assertInstanceOf(StringNode::class, $arrayItem->value);
        $arrayItem->value->value = 'new';

        $this->assertSame('["new"]', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPreservesExplicitlyChangedObjectItemColonSpacingWhenBestEffortReformatsContainer(): void
    {
        $fragment = (new JsonParser())->parse(
            <<<'JSON'
{
    "x": 1,
    "y": 2
}
JSON,
        );

        $jsonDocument = (new JsonParser())->parse('{"a" :  1, "b": 2}');
        $this->assertInstanceOf(ObjectNode::class, $fragment->value);
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $aItem = $jsonDocument->value->get('a');
        $this->assertInstanceOf(ObjectItemNode::class, $aItem);
        $aItem->value = new NumberNode('9');

        $jsonDocument->value->set('big', $fragment->value);

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($aItem);

        $this->assertSame(
            <<<'JSON'
{
    "a" :  9,
    "b": 2,
    "big": {
        "x": 1,
        "y": 2
    }
}
JSON,
            (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument),
        );
    }

    public function testItPrintsInPlaceStringMutationWhenObjectItemIsChanged(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"name":"old"}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $objectItem = $jsonDocument->value->get('name');
        $this->assertInstanceOf(ObjectItemNode::class, $objectItem);
        $this->assertInstanceOf(StringNode::class, $objectItem->value);
        $objectItem->value->value = 'new';

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($objectItem);

        $this->assertSame('{"name":"new"}', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPrintsInPlaceNumberMutationWhenObjectItemIsChanged(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"count":1}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $objectItem = $jsonDocument->value->get('count');
        $this->assertInstanceOf(ObjectItemNode::class, $objectItem);
        $this->assertInstanceOf(NumberNode::class, $objectItem->value);
        $objectItem->value->rawValue = '2';

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($objectItem);

        $this->assertSame('{"count":2}', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPrintsInPlaceBooleanMutationWhenObjectItemIsChanged(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"enabled":false}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $objectItem = $jsonDocument->value->get('enabled');
        $this->assertInstanceOf(ObjectItemNode::class, $objectItem);
        $this->assertInstanceOf(BooleanNode::class, $objectItem->value);
        $objectItem->value->value = true;

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($objectItem);

        $this->assertSame('{"enabled":true}', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPrintsInPlaceStringMutationWhenArrayItemIsChanged(): void
    {
        $jsonDocument = (new JsonParser())->parse('["old"]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $arrayItem = $jsonDocument->value->items[0];
        $this->assertInstanceOf(StringNode::class, $arrayItem->value);
        $arrayItem->value->value = 'new';

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($arrayItem);

        $this->assertSame('["new"]', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPrintsNestedInPlaceStringMutationWhenObjectIsChanged(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"meta":{"name":"old"}}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $metaItem = $jsonDocument->value->get('meta');
        $this->assertInstanceOf(ObjectItemNode::class, $metaItem);
        $this->assertInstanceOf(ObjectNode::class, $metaItem->value);

        $nameItem = $metaItem->value->get('name');
        $this->assertInstanceOf(ObjectItemNode::class, $nameItem);
        $this->assertInstanceOf(StringNode::class, $nameItem->value);
        $nameItem->value->value = 'new';

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('{"meta":{"name":"new"}}', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPreservesUnchangedNestedObjectWhenObjectIsChanged(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"meta":{"name":"old"}}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('{"meta":{"name":"old"}}', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItReindentsParsedMultilineObjectMovedDeeper(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "template": {
        "name": "json",

        "meta": {
            "active": true
        }
    },
    "target": {
        "nested": {}
    }
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $templateItem = $jsonDocument->value->get('template');
        $targetItem   = $jsonDocument->value->get('target');
        $this->assertInstanceOf(ObjectItemNode::class, $templateItem);
        $this->assertInstanceOf(ObjectItemNode::class, $targetItem);
        $this->assertInstanceOf(ObjectNode::class, $templateItem->value);
        $this->assertInstanceOf(ObjectNode::class, $targetItem->value);

        $nestedItem = $targetItem->value->get('nested');
        $this->assertInstanceOf(ObjectItemNode::class, $nestedItem);
        $nestedItem->value = $templateItem->value;

        $this->assertSame(
            <<<'JSON'
{
    "template": {
        "name": "json",

        "meta": {
            "active": true
        }
    },
    "target": {
        "nested": {
            "name": "json",

            "meta": {
                "active": true
            }
        }
    }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItReindentsParsedMultilineObjectMovedShallower(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "template": {
        "name": "json",

        "meta": {
            "active": true
        }
    }
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $templateItem = $jsonDocument->value->get('template');
        $this->assertInstanceOf(ObjectItemNode::class, $templateItem);
        $this->assertInstanceOf(ObjectNode::class, $templateItem->value);

        $jsonDocument->value = $templateItem->value;

        $this->assertSame(
            <<<'JSON'
{
    "name": "json",

    "meta": {
        "active": true
    }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPrintsNestedInPlaceStringMutationWhenArrayIsChanged(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"values":["old"]}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $valuesItem = $jsonDocument->value->get('values');
        $this->assertInstanceOf(ObjectItemNode::class, $valuesItem);
        $this->assertInstanceOf(ArrayNode::class, $valuesItem->value);
        $this->assertInstanceOf(StringNode::class, $valuesItem->value->items[0]->value);
        $valuesItem->value->items[0]->value->value = 'new';

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('{"values":["new"]}', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPreservesUnchangedNestedArrayWhenObjectIsChanged(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"values":["old"]}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('{"values":["old"]}', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPreservesUnchangedNullWhenObjectIsChanged(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"value":null}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('{"value":null}', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPreservesCommaWhitespaceWhenNewKeyIsAddedToSingleItemInlineObject(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"a": 1}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->set('b', new StringNode('hello'));

        $this->assertSame('{"a": 1, "b": "hello"}', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPreservesCommaWhitespaceWhenNewItemIsAppendedToSingleItemInlineArray(): void
    {
        $jsonDocument = (new JsonParser())->parse('[1]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->append(new StringNode('x'));

        $this->assertSame('[1, "x"]', (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItDoesNotDuplicateMultilineWhitespaceWhenAppendingToEmptyArray(): void
    {
        $jsonDocument = (new JsonParser())->parse("[\n\n]");
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->append(new NumberNode('1'));

        $this->assertSame("[\n\n    1\n]", (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItDoesNotDuplicateBlankLineInNestedEmptyArray(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "name": "my-app",
    "keywords": [

    ]
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $keywords = $jsonDocument->value->get('keywords');
        $this->assertInstanceOf(ObjectItemNode::class, $keywords);
        $this->assertInstanceOf(ArrayNode::class, $keywords->value);

        $keywords->value->append(new StringNode('php'));

        $output = (new JsonPreservingPrinter())->print($jsonDocument);

        $this->assertNotSame(
            <<<'JSON'
{
    "name": "my-app",
    "keywords": [

        "php"

    ]
}
JSON,
            $output,
        );
        $this->assertSame(
            <<<'JSON'
{
    "name": "my-app",
    "keywords": [

        "php"
    ]
}
JSON,
            $output,
        );
    }

    public function testItDoesNotDuplicateBlankLineWhenAppendingMultipleItemsToNestedEmptyArray(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "name": "my-app",
    "keywords": [

    ]
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $keywords = $jsonDocument->value->get('keywords');
        $this->assertInstanceOf(ObjectItemNode::class, $keywords);
        $this->assertInstanceOf(ArrayNode::class, $keywords->value);

        $keywords->value->append(new StringNode('php'));
        $keywords->value->append(new StringNode('go'));

        // The decorative blank line is a one-time opening decoration: it stays
        // before the first item only and must not be repeated before later items.
        $this->assertSame(
            <<<'JSON'
{
    "name": "my-app",
    "keywords": [

        "php",
        "go"
    ]
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItDoesNotDuplicateBlankLineWhenSettingMultipleKeysOnNestedEmptyObject(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "cfg": {

    }
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $cfg = $jsonDocument->value->get('cfg');
        $this->assertInstanceOf(ObjectItemNode::class, $cfg);
        $this->assertInstanceOf(ObjectNode::class, $cfg->value);

        $cfg->value->set('a', new NumberNode('1'));
        $cfg->value->set('b', new NumberNode('2'));

        $this->assertSame(
            <<<'JSON'
{
    "cfg": {

        "a": 1,
        "b": 2
    }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesIntentionalBlankLineBetweenItemsWhenAppending(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
[
    1,

    2
]
JSON,
        );
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->append(new NumberNode('3'));

        // A blank line used as an intentional inter-item separator (not the
        // container's opening decoration) is preserved for the appended item.
        $this->assertSame(
            <<<'JSON'
[
    1,

    2,

    3
]
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItDoesNotDuplicateMultilineWhitespaceWhenAppendingToEmptyObject(): void
    {
        $jsonDocument = (new JsonParser())->parse("{\n\n}");
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->set('a', new NumberNode('1'));

        $this->assertSame("{\n\n    \"a\": 1\n}", (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItPreservesSeparatorWhenInsertingIntoSingleItemArray(): void
    {
        $jsonDocument = (new JsonParser())->parse('[1]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->insert(0, new StringNode('x'));

        $this->assertSame(
            '["x", 1]',
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItRejectsInvalidUtf8String(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to encode JSON string.');

        (new JsonPreservingPrinter())->print(new StringNode("\xB1"));
    }

    public function testItPreservesBeforeCloseBraceWhenLastKeyIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse("{\n    \"a\": 1\n}");
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('a');

        $this->assertSame("{\n}", (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItDoesNotDoubleIndentObjectItemAppendedAfterAllKeysAreRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
{
    "a": 1,
    "b": 2
}
JSON,
        );
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('a');
        $jsonDocument->value->remove('b');
        $jsonDocument->value->set('c', new NumberNode('3'));

        $this->assertSame(
            <<<'JSON'
{
    "c": 3
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesTabIndentWhenObjectItemAppendedAfterAllKeysAreRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse("{\n\t\"a\": 1,\n\t\"b\": 2\n}");
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('a');
        $jsonDocument->value->remove('b');
        $jsonDocument->value->set('c', new NumberNode('3'));

        $this->assertSame(
            "{\n\t\"c\": 3\n}",
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesBeforeCloseBracketWhenLastItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse("[\n    1\n]");
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(0);

        $this->assertSame("[\n]", (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItDoesNotDoubleIndentArrayItemAppendedAfterAllItemsAreRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse(
            <<<'JSON'
[
    1,
    2
]
JSON,
        );
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(0);
        $jsonDocument->value->removeAt(0);
        $jsonDocument->value->append(new NumberNode('3'));

        $this->assertSame(
            <<<'JSON'
[
    3
]
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesTabIndentWhenArrayItemAppendedAfterAllItemsAreRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse("[\n\t1,\n\t2\n]");
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(0);
        $jsonDocument->value->removeAt(0);
        $jsonDocument->value->append(new NumberNode('3'));

        $this->assertSame(
            "[\n\t3\n]",
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invokeJsonPreservingPrinterMethod(string $methodName, array $arguments): mixed
    {
        $reflectionMethod = new ReflectionMethod(JsonPreservingPrinter::class, $methodName);

        return $reflectionMethod->invokeArgs(new JsonPreservingPrinter(), $arguments);
    }

    public function testItPreservesResidualWhitespaceWhenReindentingToTabs(): void
    {
        $this->assertSame("\t", $this->invokeJsonPreservingPrinterMethod(
            'reindentLeadingWhitespaceUnit',
            ['    ', '    ', "\t", 0],
        ));
        $this->assertSame("\t  ", $this->invokeJsonPreservingPrinterMethod(
            'reindentLeadingWhitespaceUnit',
            ['      ', '    ', "\t", 0],
        ));
        $this->assertSame("\t\t", $this->invokeJsonPreservingPrinterMethod(
            'reindentLeadingWhitespaceUnit',
            ['        ', '    ', "\t", 0],
        ));
        $this->assertSame('', $this->invokeJsonPreservingPrinterMethod(
            'reindentLeadingWhitespaceUnit',
            ['    ', '    ', "\t", -2],
        ));
    }

    public function testItPreservesOnlyPositiveResidualWhitespaceForEmptyTargetIndent(): void
    {
        $this->assertSame(' ', $this->invokeJsonPreservingPrinterMethod(
            'reindentLeadingWhitespaceUnit',
            ['     ', '    ', '', 0],
        ));
        $this->assertSame('', $this->invokeJsonPreservingPrinterMethod(
            'reindentLeadingWhitespaceUnit',
            ['    ', '    ', '', 0],
        ));
    }

    public function testItUsesEmptyDocumentIndentWhenPrintingNewContainers(): void
    {
        $jsonDocument = new JsonDocument(new ObjectNode([
            new ObjectItemNode(new StringNode('keywords'), new ArrayNode([
                new ArrayItemNode(new StringNode('json')),
                new ArrayItemNode(new StringNode('ast')),
            ])),
        ]));
        $jsonDocument->setAttribute(NodeAttributes::INDENT, '');

        $this->assertSame(
            <<<'JSON'
    {
    "keywords": [
    "json",
    "ast"
    ]
    }
    JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }
}
