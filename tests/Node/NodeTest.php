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
