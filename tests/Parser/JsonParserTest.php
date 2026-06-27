<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Parser;

use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\Parser\JsonParser;
use Boundwize\JsonRecast\Parser\ParseError;
use PHPUnit\Framework\TestCase;

final class JsonParserTest extends TestCase
{
    public function testItParsesString(): void
    {
        $jsonDocument = (new JsonParser())->parse('"hello"');

        $this->assertInstanceOf(StringNode::class, $jsonDocument->value);
        $this->assertSame('hello', $jsonDocument->value->value);
    }

    public function testItParsesNumberWithRawValue(): void
    {
        $jsonDocument = (new JsonParser())->parse('1.0');

        $this->assertInstanceOf(NumberNode::class, $jsonDocument->value);
        $this->assertSame('1.0', $jsonDocument->value->rawValue);
    }

    public function testItParsesObject(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"name":"boundwize"}');

        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $this->assertCount(1, $jsonDocument->value->items);
        $this->assertSame('name', $jsonDocument->value->items[0]->key->value);
        $this->assertInstanceOf(StringNode::class, $jsonDocument->value->items[0]->value);
        $this->assertSame('boundwize', $jsonDocument->value->items[0]->value->value);
    }

    public function testItParsesArray(): void
    {
        $jsonDocument = (new JsonParser())->parse('["a","b"]');

        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);
        $this->assertCount(2, $jsonDocument->value->items);
        $this->assertInstanceOf(StringNode::class, $jsonDocument->value->items[0]->value);
        $this->assertSame('a', $jsonDocument->value->items[0]->value->value);
        $this->assertInstanceOf(StringNode::class, $jsonDocument->value->items[1]->value);
        $this->assertSame('b', $jsonDocument->value->items[1]->value->value);
    }

    public function testItParsesRecursiveObject(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"a":{"b":{"c":true}}}');

        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $a = $jsonDocument->value->items[0]->value;
        $this->assertInstanceOf(ObjectNode::class, $a);
        $b = $a->items[0]->value;
        $this->assertInstanceOf(ObjectNode::class, $b);
        $c = $b->items[0]->value;
        $this->assertInstanceOf(BooleanNode::class, $c);
        $this->assertTrue($c->value);
    }

    public function testItParsesRecursiveArray(): void
    {
        $jsonDocument = (new JsonParser())->parse('[[[1]]]');

        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);
        $second = $jsonDocument->value->items[0]->value;
        $this->assertInstanceOf(ArrayNode::class, $second);
        $third = $second->items[0]->value;
        $this->assertInstanceOf(ArrayNode::class, $third);
        $this->assertInstanceOf(NumberNode::class, $third->items[0]->value);
        $this->assertSame('1', $third->items[0]->value->rawValue);
    }

    public function testItParsesMixedRecursiveObjectAndArray(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"items":[{"name":"first"},{"name":"second"}]}');

        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $items = $jsonDocument->value->items[0]->value;
        $this->assertInstanceOf(ArrayNode::class, $items);

        $first = $items->items[0]->value;
        $this->assertInstanceOf(ObjectNode::class, $first);
        $this->assertInstanceOf(StringNode::class, $first->items[0]->value);
        $this->assertSame('first', $first->items[0]->value->value);

        $second = $items->items[1]->value;
        $this->assertInstanceOf(ObjectNode::class, $second);
        $this->assertInstanceOf(StringNode::class, $second->items[0]->value);
        $this->assertSame('second', $second->items[0]->value->value);
    }

    public function testItRejectsTrailingComma(): void
    {
        $this->expectException(ParseError::class);

        (new JsonParser())->parse('{"name":"boundwize",}');
    }

    public function testItRejectsComment(): void
    {
        $this->expectException(ParseError::class);

        (new JsonParser())->parse(<<<'JSON'
{
    // comment
    "name": "boundwize"
}
JSON);
    }

    public function testItRejectsLeadingZeroNumber(): void
    {
        $this->expectException(ParseError::class);

        (new JsonParser())->parse('01');
    }
}
