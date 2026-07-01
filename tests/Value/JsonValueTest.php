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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const INF;
use const NAN;

final class JsonValueTest extends TestCase
{
    public function testItCreatesScalarNodes(): void
    {
        $this->assertInstanceOf(StringNode::class, JsonValue::from('json'));
        $this->assertInstanceOf(NumberNode::class, JsonValue::from(1));
        $this->assertInstanceOf(NumberNode::class, JsonValue::from(1.5));
        $this->assertInstanceOf(BooleanNode::class, JsonValue::from(true));
        $this->assertInstanceOf(NullNode::class, JsonValue::from(null));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteFloatProvider(): iterable
    {
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('nonFiniteFloatProvider')]
    public function testItRejectsNonFiniteFloat(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonValue::from($value);
    }

    public function testJsonValueCreatesRecursiveObject(): void
    {
        $nodeJson = JsonValue::from([
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'src/',
                ],
            ],
        ]);

        $this->assertInstanceOf(ObjectNode::class, $nodeJson);
        $this->assertSame('autoload', $nodeJson->items[0]->key->value);

        $autoload = $nodeJson->items[0]->value;
        $this->assertInstanceOf(ObjectNode::class, $autoload);
        $this->assertSame('psr-4', $autoload->items[0]->key->value);

        $psr4 = $autoload->items[0]->value;
        $this->assertInstanceOf(ObjectNode::class, $psr4);
        $this->assertSame('App\\', $psr4->items[0]->key->value);
        $this->assertInstanceOf(StringNode::class, $psr4->items[0]->value);
        $this->assertSame('src/', $psr4->items[0]->value->value);
    }

    public function testJsonValueCreatesRecursiveArray(): void
    {
        $nodeJson = JsonValue::from([
            ['json'],
        ]);

        $this->assertInstanceOf(ArrayNode::class, $nodeJson);
        $nested = $nodeJson->items[0]->value;
        $this->assertInstanceOf(ArrayNode::class, $nested);
        $this->assertInstanceOf(StringNode::class, $nested->items[0]->value);
        $this->assertSame('json', $nested->items[0]->value->value);
    }
}
