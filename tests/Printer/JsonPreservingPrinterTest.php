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
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_reverse;

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

    public function testItPreservesParsedArrayNode(): void
    {
        $jsonDocument = (new JsonParser())->parse('["json"]');

        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);
        $this->assertSame('["json"]', (new JsonPreservingPrinter())->print($jsonDocument->value));
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

    public function testItPreservesTrailingNewlineWhenDocumentAfterValueIsEmpty(): void
    {
        $jsonDocument = new JsonDocument(new StringNode('json'));
        $jsonDocument->setAttribute(NodeAttributes::NEWLINE, "\r\n");
        $jsonDocument->setAttribute(NodeAttributes::TRAILING_NEWLINE, true);

        $this->assertSame("\"json\"\r\n", (new JsonPreservingPrinter())->print($jsonDocument));
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
            "{\"city\": \"" . $city . "\",\"note\": \"" . $note . "\"}",
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

    public function testItRejectsInvalidUtf8String(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to encode JSON string.');

        (new JsonPreservingPrinter())->print(new StringNode("\xB1"));
    }
}
