<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Node;

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

    public function testNumberNodeConvertsRawIntegersAndFloats(): void
    {
        $this->assertSame(10, (new NumberNode('10'))->toIntOrFloat());
        $this->assertEqualsWithDelta(1.5, (new NumberNode('1.5'))->toIntOrFloat(), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(100.0, (new NumberNode('1e2'))->toIntOrFloat(), PHP_FLOAT_EPSILON);
    }
}
