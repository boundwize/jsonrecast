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
use stdClass;

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
     * @return iterable<string, array{float, string}>
     */
    public static function finiteFloatProvider(): iterable
    {
        yield 'fractional float' => [1.5, '1.5'];
        yield 'whole-number float' => [1.0, '1.0'];
        yield 'negative zero float' => [-0.0, '-0.0'];
        yield 'negative whole-number float' => [-2.0, '-2.0'];
        yield 'scientific notation float' => [1.0E-5, '1.0e-5'];
        yield 'large scientific notation float' => [1.0E+300, '1.0e+300'];
        yield 'pi precision' => [3.14159265358979, '3.14159265358979'];
        yield 'large fractional precision' => [19999999999999.996, '19999999999999.996'];
        yield 'recurring fractional precision' => [1 / 3, '0.3333333333333333'];
    }

    #[DataProvider('finiteFloatProvider')]
    public function testItPreservesFiniteFloatRawValue(float $value, string $expectedRawValue): void
    {
        $nodeJson = JsonValue::from($value);

        $this->assertInstanceOf(NumberNode::class, $nodeJson);
        $this->assertSame($expectedRawValue, $nodeJson->rawValue);
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

    public function testItPreservesEmptyObjectValueFromPhpData(): void
    {
        $nodeJson = JsonValue::from([
            'config' => new stdClass(),
        ]);

        $this->assertInstanceOf(ObjectNode::class, $nodeJson);

        $config = $nodeJson->items[0]->value;

        $this->assertInstanceOf(ObjectNode::class, $config);
    }

    public function testItCreatesEmptyObjectNode(): void
    {
        $nodeJson = JsonValue::from(new stdClass());
        $this->assertInstanceOf(ObjectNode::class, $nodeJson);
        $this->assertCount(0, $nodeJson->items);
    }

    public function testItCreatesObjectNodeFromStdClassWithProperties(): void
    {
        $obj      = new stdClass();
        $obj->foo = 'bar';

        $nodeJson = JsonValue::from($obj);
        $this->assertInstanceOf(ObjectNode::class, $nodeJson);
        $this->assertCount(1, $nodeJson->items);
        $this->assertSame('foo', $nodeJson->items[0]->key->value);
        $this->assertInstanceOf(StringNode::class, $nodeJson->items[0]->value);
        $this->assertSame('bar', $nodeJson->items[0]->value->value);
    }
}
