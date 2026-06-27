<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Value;

use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\Value\JsonValue;
use PHPUnit\Framework\TestCase;

final class JsonValueTest extends TestCase
{
    public function testItCreatesScalarNodes(): void
    {
        self::assertInstanceOf(StringNode::class, JsonValue::from('json'));
        self::assertInstanceOf(NumberNode::class, JsonValue::from(1));
        self::assertInstanceOf(NumberNode::class, JsonValue::from(1.5));
        self::assertInstanceOf(BooleanNode::class, JsonValue::from(true));
        self::assertInstanceOf(NullNode::class, JsonValue::from(null));
    }

    public function testJsonValueCreatesRecursiveObject(): void
    {
        $node = JsonValue::from([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ]);

        self::assertInstanceOf(ObjectNode::class, $node);
        self::assertSame('autoload', $node->items[0]->key->value);

        $autoload = $node->items[0]->value;
        self::assertInstanceOf(ObjectNode::class, $autoload);
        self::assertSame('psr-4', $autoload->items[0]->key->value);

        $psr4 = $autoload->items[0]->value;
        self::assertInstanceOf(ObjectNode::class, $psr4);
        self::assertSame('App\\', $psr4->items[0]->key->value);
        self::assertInstanceOf(StringNode::class, $psr4->items[0]->value);
        self::assertSame('src/', $psr4->items[0]->value->value);
    }

    public function testJsonValueCreatesRecursiveArray(): void
    {
        $node = JsonValue::from([
            ['json'],
        ]);

        self::assertInstanceOf(ArrayNode::class, $node);
        $nested = $node->items[0]->value;
        self::assertInstanceOf(ArrayNode::class, $nested);
        self::assertInstanceOf(StringNode::class, $nested->items[0]->value);
        self::assertSame('json', $nested->items[0]->value->value);
    }
}
