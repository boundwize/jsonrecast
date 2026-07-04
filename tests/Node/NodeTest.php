<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Node;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use PHPUnit\Framework\TestCase;

use const PHP_FLOAT_EPSILON;

final class NodeTest extends TestCase
{
    public function testNodeAttributesCanBeListedAndRemoved(): void
    {
        $stringNode = new StringNode('value');

        $stringNode->setAttribute('source', 'test');

        $this->assertSame(['source' => 'test'], $stringNode->getAttributes());
        $this->assertTrue($stringNode->hasAttribute('source'));

        $stringNode->removeAttribute('source');

        $this->assertSame([], $stringNode->getAttributes());
        $this->assertFalse($stringNode->hasAttribute('source'));
    }

    public function testObjectNodeCanLookupKeysAndReportMissingKeys(): void
    {
        $objectItemNode = new ObjectItemNode(new StringNode('name'), new StringNode('jsonrecast'));
        $objectNode     = new ObjectNode([$objectItemNode]);

        $this->assertSame($objectItemNode, $objectNode->get('name'));
        $this->assertTrue($objectNode->has('name'));
        $this->assertNotInstanceOf(ObjectItemNode::class, $objectNode->get('missing'));
        $this->assertFalse($objectNode->has('missing'));
        $this->assertFalse($objectNode->remove('missing'));
    }

    public function testObjectNodeLookupReturnsLastDuplicateKey(): void
    {
        $firstNameItem = new ObjectItemNode(new StringNode('name'), new StringNode('first'));
        $lastNameItem  = new ObjectItemNode(new StringNode('name'), new StringNode('last'));
        $objectNode    = new ObjectNode([
            $firstNameItem,
            new ObjectItemNode(new StringNode('type'), new StringNode('library')),
            $lastNameItem,
        ]);

        $this->assertSame($lastNameItem, $objectNode->get('name'));
        $this->assertTrue($objectNode->has('name'));
    }

    public function testObjectNodeSetUpdatesLastDuplicateKeyAndRemovesEarlierDuplicates(): void
    {
        $objectNode = new ObjectNode([
            new ObjectItemNode(new StringNode('name'), new StringNode('first')),
            new ObjectItemNode(new StringNode('type'), new StringNode('library')),
            new ObjectItemNode(new StringNode('name'), new StringNode('last')),
        ]);

        $objectNode->set('name', new StringNode('changed'));

        $this->assertCount(2, $objectNode->items);
        $this->assertSame('type', $objectNode->items[0]->key->value);
        $this->assertSame('name', $objectNode->items[1]->key->value);
        $this->assertSame($objectNode->items[1], $objectNode->get('name'));
        $this->assertInstanceOf(StringNode::class, $objectNode->items[1]->value);
        $this->assertSame('changed', $objectNode->items[1]->value->value);
    }

    public function testObjectNodeSetAppendsMissingKeyWithWhitespaceFromMultipleItems(): void
    {
        $firstItem  = new ObjectItemNode(new StringNode('name'), new StringNode('jsonrecast'), afterValue: ' ');
        $secondItem = new ObjectItemNode(
            key: new StringNode('type'),
            value: new StringNode('library'),
            beforeKey: "\n    ",
            betweenColonAndValue: ' ',
            afterValue: "\n",
        );
        $secondItem->setAttribute(NodeAttributes::ORIGINAL_TEXT, "\n    \"type\": \"library\"\n");

        $objectNode = new ObjectNode([$firstItem, $secondItem], afterOpenBrace: "\n    ", beforeCloseBrace: "\n");

        $objectNode->set('license', new StringNode('MIT'));

        $this->assertCount(3, $objectNode->items);
        $this->assertSame(' ', $secondItem->afterValue);
        $this->assertNull($secondItem->getAttribute(NodeAttributes::ORIGINAL_TEXT));
        $this->assertSame('license', $objectNode->items[2]->key->value);
        $this->assertSame("\n    ", $objectNode->items[2]->beforeKey);
        $this->assertSame(' ', $objectNode->items[2]->betweenColonAndValue);
        $this->assertSame("\n", $objectNode->items[2]->afterValue);
    }

    public function testObjectNodeRemoveRemovesEveryDuplicateKey(): void
    {
        $objectNode = new ObjectNode([
            new ObjectItemNode(new StringNode('name'), new StringNode('first')),
            new ObjectItemNode(new StringNode('type'), new StringNode('library')),
            new ObjectItemNode(new StringNode('name'), new StringNode('last')),
        ]);

        $this->assertTrue($objectNode->remove('name'));

        $this->assertCount(1, $objectNode->items);
        $this->assertSame('type', $objectNode->items[0]->key->value);
        $this->assertFalse($objectNode->has('name'));
    }

    public function testArrayNodeReturnsFalseWhenRemovingMissingIndex(): void
    {
        $arrayNode = new ArrayNode([]);

        $this->assertFalse($arrayNode->removeAt(0));
    }

    public function testArrayNodeSetAtReplacesExistingItemAndInvalidatesOriginalText(): void
    {
        $arrayItemNode = new ArrayItemNode(new StringNode('old'));
        $arrayItemNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '"old"');

        $arrayNode  = new ArrayNode([$arrayItemNode]);
        $stringNode = new StringNode('new');

        $this->assertTrue($arrayNode->setAt(0, $stringNode));
        $this->assertSame($stringNode, $arrayNode->items[0]->value);
        $this->assertTrue($arrayNode->items[0]->hasAttribute(NodeAttributes::ORIGINAL_TEXT));
        $this->assertNull($arrayNode->items[0]->getAttribute(NodeAttributes::ORIGINAL_TEXT));
        $this->assertFalse($arrayNode->setAt(1, new StringNode('missing')));
    }

    public function testArrayNodeInsertBeforeFirstItemNormalizesNegativeIndexAndInvalidatesShiftedItem(): void
    {
        $arrayItemNode = new ArrayItemNode(new StringNode('existing'), beforeValue: "\n    ");
        $arrayItemNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, "\n    \"existing\"");

        $arrayNode = new ArrayNode([$arrayItemNode], afterOpenBracket: "\n    ", beforeCloseBracket: "\n");

        $arrayNode->insert(-10, new StringNode('new'));

        $this->assertCount(2, $arrayNode->items);
        $this->assertInstanceOf(StringNode::class, $arrayNode->items[0]->value);
        $this->assertSame('new', $arrayNode->items[0]->value->value);
        $this->assertSame("\n    ", $arrayNode->items[0]->beforeValue);
        $this->assertSame('', $arrayNode->items[0]->afterValue);
        $this->assertSame("\n    ", $arrayItemNode->beforeValue);
        $this->assertNull($arrayItemNode->getAttribute(NodeAttributes::ORIGINAL_TEXT));
    }

    public function testArrayNodeInsertIntoEmptyArrayClampsOutOfRangeIndex(): void
    {
        $arrayNode = new ArrayNode([], afterOpenBracket: ' ', beforeCloseBracket: ' ');

        $arrayNode->insert(99, new StringNode('new'));

        $this->assertCount(1, $arrayNode->items);
        $this->assertInstanceOf(StringNode::class, $arrayNode->items[0]->value);
        $this->assertSame('new', $arrayNode->items[0]->value->value);
        $this->assertSame(' ', $arrayNode->items[0]->beforeValue);
        $this->assertSame(' ', $arrayNode->items[0]->afterValue);
    }

    public function testArrayNodeAppendUsesPhysicalLastItemStyleWhenExistingItemsHaveNoNumericStartOffsets(): void
    {
        $firstItem = new ArrayItemNode(new StringNode('first'), beforeValue: ' ', afterValue: ' | ');
        $lastItem  = new ArrayItemNode(new StringNode('second'), beforeValue: '\t', afterValue: "\n");

        $arrayNode = new ArrayNode([$firstItem, $lastItem], afterOpenBracket: ' ', beforeCloseBracket: "\n");

        $arrayNode->append(new StringNode('third'));

        $this->assertCount(3, $arrayNode->items);
        $this->assertSame(' | ', $lastItem->afterValue);
        $this->assertNull($lastItem->getAttribute(NodeAttributes::ORIGINAL_TEXT));
        $this->assertInstanceOf(StringNode::class, $arrayNode->items[2]->value);
        $this->assertSame('third', $arrayNode->items[2]->value->value);
        $this->assertSame('\t', $arrayNode->items[2]->beforeValue);
        $this->assertSame("\n", $arrayNode->items[2]->afterValue);
    }

    public function testObjectNodeSetAppendsMissingKeyWithWhitespaceFromSingleItem(): void
    {
        $objectItemNode = new ObjectItemNode(
            key: new StringNode('a'),
            value: new NumberNode('1'),
            beforeKey: ' ',
            betweenColonAndValue: ' ',
            afterValue: '',
        );
        $objectItemNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, ' "a": 1');

        $objectNode = new ObjectNode([$objectItemNode], afterOpenBrace: '', beforeCloseBrace: '');

        $objectNode->set('b', new StringNode('hello'));

        $this->assertCount(2, $objectNode->items);
        // The new item must inherit the single existing item's beforeKey (' ')
        $this->assertSame(' ', $objectNode->items[1]->beforeKey);
        $this->assertSame(' ', $objectNode->items[1]->betweenColonAndValue);
        $this->assertSame('b', $objectNode->items[1]->key->value);
        $this->assertInstanceOf(StringNode::class, $objectNode->items[1]->value);
        $this->assertSame('hello', $objectNode->items[1]->value->value);
    }

    public function testArrayNodeAppendPreservesBeforeValueFromSingleExistingItem(): void
    {
        $arrayItemNode = new ArrayItemNode(new NumberNode('1'), beforeValue: ' ', afterValue: '');
        $arrayItemNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, ' 1');

        $arrayNode = new ArrayNode([$arrayItemNode], afterOpenBracket: '', beforeCloseBracket: '');

        $arrayNode->append(new StringNode('x'));

        $this->assertCount(2, $arrayNode->items);
        // The appended item must inherit the single existing item's beforeValue (' ')
        $this->assertSame(' ', $arrayNode->items[1]->beforeValue);
        $this->assertInstanceOf(StringNode::class, $arrayNode->items[1]->value);
        $this->assertSame('x', $arrayNode->items[1]->value->value);
    }

    public function testNumberNodeConvertsRawIntegersAndFloats(): void
    {
        $this->assertSame(10, (new NumberNode('10'))->toIntOrFloat());
        $this->assertEqualsWithDelta(1.5, (new NumberNode('1.5'))->toIntOrFloat(), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(100.0, (new NumberNode('1e2'))->toIntOrFloat(), PHP_FLOAT_EPSILON);
    }

    public function testNumberNodeToIntOrFloatDoesNotClampPositiveLargeInteger(): void
    {
        $largeIntegerValue = (new NumberNode('9223372036854775808'))->toIntOrFloat();

        $this->assertIsFloat($largeIntegerValue);
        $this->assertSame((float) '9223372036854775808', $largeIntegerValue);
    }

    public function testNumberNodeToIntOrFloatDoesNotClampNegativeLargeInteger(): void
    {
        $largeNegativeIntegerValue = (new NumberNode('-9223372036854775809'))->toIntOrFloat();

        $this->assertIsFloat($largeNegativeIntegerValue);
        $this->assertSame((float) '-9223372036854775809', $largeNegativeIntegerValue);
    }

    public function testObjectNodeSetAppendsToEmptyObjectUsingAfterOpenBraceAsBeforeKey(): void
    {
        $objectNode = new ObjectNode([], afterOpenBrace: ' ', beforeCloseBrace: ' ');

        $objectNode->set('a', new StringNode('hello'));

        $this->assertCount(1, $objectNode->items);
        // Empty object falls back to afterOpenBrace for the first item's beforeKey
        $this->assertSame(' ', $objectNode->items[0]->beforeKey);
        $this->assertSame('a', $objectNode->items[0]->key->value);
    }

    public function testArrayNodeAppendToEmptyArrayUsesAfterOpenBracketAsBeforeValue(): void
    {
        $arrayNode = new ArrayNode([], afterOpenBracket: ' ', beforeCloseBracket: ' ');

        $arrayNode->append(new StringNode('x'));

        $this->assertCount(1, $arrayNode->items);
        // Empty array falls back to afterOpenBracket for the first item's beforeValue
        $this->assertSame(' ', $arrayNode->items[0]->beforeValue);
        $this->assertInstanceOf(StringNode::class, $arrayNode->items[0]->value);
        $this->assertSame('x', $arrayNode->items[0]->value->value);
    }
}
