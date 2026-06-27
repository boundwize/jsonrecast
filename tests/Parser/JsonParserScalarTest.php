<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Parser;

use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\Parser\JsonParser;
use PHPUnit\Framework\TestCase;

final class JsonParserScalarTest extends TestCase
{
    public function testItParsesFalseAndNull(): void
    {
        $jsonDocument = (new JsonParser())->parse('false');
        $nullDocument = (new JsonParser())->parse('null');

        $this->assertInstanceOf(BooleanNode::class, $jsonDocument->value);
        $this->assertFalse($jsonDocument->value->value);
        $this->assertInstanceOf(NullNode::class, $nullDocument->value);
    }

    public function testItParsesEmptyCollections(): void
    {
        $jsonDocument  = (new JsonParser())->parse('{}');
        $arrayDocument = (new JsonParser())->parse('[]');

        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $this->assertSame([], $jsonDocument->value->items);
        $this->assertInstanceOf(ArrayNode::class, $arrayDocument->value);
        $this->assertSame([], $arrayDocument->value->items);
    }

    public function testItParsesEscapedUnicodeAndComplexNumbers(): void
    {
        $jsonDocument   = (new JsonParser())->parse('"\u0041"');
        $numberDocument = (new JsonParser())->parse('-1.2e+3');

        $this->assertInstanceOf(StringNode::class, $jsonDocument->value);
        $this->assertSame('A', $jsonDocument->value->value);
        $this->assertInstanceOf(NumberNode::class, $numberDocument->value);
        $this->assertSame('-1.2e+3', $numberDocument->value->rawValue);
    }

    public function testItDetectsCarriageReturnNewlineStyle(): void
    {
        $jsonDocument = (new JsonParser())->parse("{\r\"enabled\": false\r}");

        $this->assertSame("\r", $jsonDocument->getAttribute('newline'));
    }
}
