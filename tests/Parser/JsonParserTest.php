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
        $document = (new JsonParser())->parse('"hello"');

        self::assertInstanceOf(StringNode::class, $document->value);
        self::assertSame('hello', $document->value->value);
    }

    public function testItParsesNumberWithRawValue(): void
    {
        $document = (new JsonParser())->parse('1.0');

        self::assertInstanceOf(NumberNode::class, $document->value);
        self::assertSame('1.0', $document->value->rawValue);
    }

    public function testItParsesObject(): void
    {
        $document = (new JsonParser())->parse('{"name":"boundwize"}');

        self::assertInstanceOf(ObjectNode::class, $document->value);
        self::assertCount(1, $document->value->items);
        self::assertSame('name', $document->value->items[0]->key->value);
        self::assertInstanceOf(StringNode::class, $document->value->items[0]->value);
        self::assertSame('boundwize', $document->value->items[0]->value->value);
    }

    public function testItParsesArray(): void
    {
        $document = (new JsonParser())->parse('["a","b"]');

        self::assertInstanceOf(ArrayNode::class, $document->value);
        self::assertCount(2, $document->value->items);
        self::assertInstanceOf(StringNode::class, $document->value->items[0]->value);
        self::assertSame('a', $document->value->items[0]->value->value);
        self::assertInstanceOf(StringNode::class, $document->value->items[1]->value);
        self::assertSame('b', $document->value->items[1]->value->value);
    }

    public function testItParsesRecursiveObject(): void
    {
        $document = (new JsonParser())->parse('{"a":{"b":{"c":true}}}');

        self::assertInstanceOf(ObjectNode::class, $document->value);
        $a = $document->value->items[0]->value;
        self::assertInstanceOf(ObjectNode::class, $a);
        $b = $a->items[0]->value;
        self::assertInstanceOf(ObjectNode::class, $b);
        $c = $b->items[0]->value;
        self::assertInstanceOf(BooleanNode::class, $c);
        self::assertTrue($c->value);
    }

    public function testItParsesRecursiveArray(): void
    {
        $document = (new JsonParser())->parse('[[[1]]]');

        self::assertInstanceOf(ArrayNode::class, $document->value);
        $second = $document->value->items[0]->value;
        self::assertInstanceOf(ArrayNode::class, $second);
        $third = $second->items[0]->value;
        self::assertInstanceOf(ArrayNode::class, $third);
        self::assertInstanceOf(NumberNode::class, $third->items[0]->value);
        self::assertSame('1', $third->items[0]->value->rawValue);
    }

    public function testItParsesMixedRecursiveObjectAndArray(): void
    {
        $document = (new JsonParser())->parse('{"items":[{"name":"first"},{"name":"second"}]}');

        self::assertInstanceOf(ObjectNode::class, $document->value);
        $items = $document->value->items[0]->value;
        self::assertInstanceOf(ArrayNode::class, $items);

        $first = $items->items[0]->value;
        self::assertInstanceOf(ObjectNode::class, $first);
        self::assertInstanceOf(StringNode::class, $first->items[0]->value);
        self::assertSame('first', $first->items[0]->value->value);

        $second = $items->items[1]->value;
        self::assertInstanceOf(ObjectNode::class, $second);
        self::assertInstanceOf(StringNode::class, $second->items[0]->value);
        self::assertSame('second', $second->items[0]->value->value);
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
