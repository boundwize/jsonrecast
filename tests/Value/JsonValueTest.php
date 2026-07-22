<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Value;

use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\Tests\Value\Fixture\IntegerBackedPriority;
use Boundwize\JsonRecast\Tests\Value\Fixture\PureDirection;
use Boundwize\JsonRecast\Tests\Value\Fixture\SerializableDirection;
use Boundwize\JsonRecast\Tests\Value\Fixture\SerializableLink;
use Boundwize\JsonRecast\Tests\Value\Fixture\StringBackedStatus;
use Boundwize\JsonRecast\Value\JsonValue;
use InvalidArgumentException;
use JsonSerializable;
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

    public function testItRejectsInvalidUtf8StringValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('String value is not valid UTF-8.');

        JsonValue::from("\xC3\x28");
    }

    public function testItRejectsInvalidUtf8ObjectKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('String value is not valid UTF-8.');

        JsonValue::from(["\xC3\x28" => 1]);
    }

    public function testItRejectsInvalidUtf8ObjectPropertyName(): void
    {
        $value               = new stdClass();
        $value->{"\xC3\x28"} = 1;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('String value is not valid UTF-8.');

        JsonValue::from($value);
    }

    public function testItAcceptsMultibyteAndEmbeddedNulStrings(): void
    {
        $this->assertInstanceOf(StringNode::class, JsonValue::from('café 😀'));
        $this->assertInstanceOf(StringNode::class, JsonValue::from("a\x00b"));
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

    public function testMaximumNestingDepthResetsForNextInlinePhpArraySibling(): void
    {
        $nodeJson = JsonValue::from([1 => [2], 2], maximumDepth: 3);

        $this->assertInstanceOf(ObjectNode::class, $nodeJson);
        $this->assertCount(2, $nodeJson->items);
        $this->assertSame('1', $nodeJson->items[0]->key->value);
        $this->assertInstanceOf(ArrayNode::class, $nodeJson->items[0]->value);
        $this->assertSame('2', $nodeJson->items[1]->key->value);
        $this->assertInstanceOf(NumberNode::class, $nodeJson->items[1]->value);
        $this->assertSame('2', $nodeJson->items[1]->value->rawValue);
    }

    public function testMaximumNestingDepthIsCheckedWhenEnteringPhpArrayStack(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        JsonValue::from([1 => [2], 2], maximumDepth: 2);
    }

    public function testItRejectsValueThatExceedsMaximumNestingDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        JsonValue::from($this->nestedArray(512));
    }

    public function testMaximumNestingDepthCanBeOverridden(): void
    {
        $nodeJson = JsonValue::from($this->nestedArray(512), maximumDepth: 513);

        $this->assertInstanceOf(ArrayNode::class, $nodeJson);
    }

    public function testItAcceptsEmptyCollectionAtMaximumNestingDepth(): void
    {
        // value conversion mirrors json_encode(), which lets an empty container occupy
        // the final depth level (json_encode([[]], depth: 2) succeeds), while parsing
        // mirrors json_decode(), which rejects it (json_decode('[[]]', depth: 2))
        $this->assertInstanceOf(ArrayNode::class, JsonValue::from([], maximumDepth: 1));
        $this->assertInstanceOf(ObjectNode::class, JsonValue::from(new stdClass(), maximumDepth: 1));
        $this->assertInstanceOf(ArrayNode::class, JsonValue::from([[]], maximumDepth: 2));
        $this->assertInstanceOf(ObjectNode::class, JsonValue::from(['value' => new stdClass()], maximumDepth: 2));
    }

    public function testMaximumNestingDepthMustBeGreaterThanZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum depth must be greater than 0.');

        JsonValue::from(0, maximumDepth: 0);
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

    public function testItCreatesStringNodeFromStringBackedEnum(): void
    {
        $nodeJson = JsonValue::from(StringBackedStatus::Active);

        $this->assertInstanceOf(StringNode::class, $nodeJson);
        $this->assertSame('active', $nodeJson->value);
    }

    public function testItCreatesNumberNodeFromIntegerBackedEnum(): void
    {
        $nodeJson = JsonValue::from(IntegerBackedPriority::High);

        $this->assertInstanceOf(NumberNode::class, $nodeJson);
        $this->assertSame('10', $nodeJson->rawValue);
    }

    public function testItCreatesScalarNodeFromBackedEnumInsideArray(): void
    {
        $nodeJson = JsonValue::from(['status' => StringBackedStatus::Active]);

        $this->assertInstanceOf(ObjectNode::class, $nodeJson);
        $this->assertInstanceOf(StringNode::class, $nodeJson->items[0]->value);
        $this->assertSame('active', $nodeJson->items[0]->value->value);
    }

    public function testItCreatesNodeFromJsonSerializableRepresentation(): void
    {
        $nodeJson = JsonValue::from(
            new class implements JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    return 'jsonrecast';
                }
            },
        );

        $this->assertInstanceOf(StringNode::class, $nodeJson);
        $this->assertSame('jsonrecast', $nodeJson->value);
    }

    public function testItCreatesRecursiveNodeFromJsonSerializableArrayRepresentation(): void
    {
        $nodeJson = JsonValue::from(
            new class implements JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    return ['name' => 'jsonrecast'];
                }
            },
        );

        $this->assertInstanceOf(ObjectNode::class, $nodeJson);
        $this->assertSame('name', $nodeJson->items[0]->key->value);
        $this->assertInstanceOf(StringNode::class, $nodeJson->items[0]->value);
        $this->assertSame('jsonrecast', $nodeJson->items[0]->value->value);
    }

    public function testItSerializesObjectPropertiesWhenJsonSerializeReturnsSelf(): void
    {
        $nodeJson = JsonValue::from(
            new class implements JsonSerializable {
                public string $name = 'jsonrecast';

                public function jsonSerialize(): mixed
                {
                    return $this;
                }
            },
        );

        $this->assertInstanceOf(ObjectNode::class, $nodeJson);
        $this->assertSame('name', $nodeJson->items[0]->key->value);
        $this->assertInstanceOf(StringNode::class, $nodeJson->items[0]->value);
        $this->assertSame('jsonrecast', $nodeJson->items[0]->value->value);
    }

    public function testItRejectsSelfReferencingJsonSerializableThatExceedsMaximumNestingDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        JsonValue::from(
            new class implements JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    return [$this];
                }
            },
        );
    }

    public function testItRejectsCyclicJsonSerializableObjects(): void
    {
        $serializableLink = new SerializableLink();
        $otherLink        = new SerializableLink($serializableLink);

        $serializableLink->next = $otherLink;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        JsonValue::from($serializableLink);
    }

    public function testItRejectsJsonSerializableChainExceedingMaximumNestingDepth(): void
    {
        $value = 'end';

        for ($link = 0; $link < 600; $link++) {
            $value = new SerializableLink($value);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        JsonValue::from($value);
    }

    public function testItAcceptsJsonSerializableChainWithinMaximumNestingDepth(): void
    {
        $value = 'end';

        for ($link = 0; $link < 10; $link++) {
            $value = new SerializableLink($value);
        }

        $nodeJson = JsonValue::from($value);

        $this->assertInstanceOf(StringNode::class, $nodeJson);
        $this->assertSame('end', $nodeJson->value);
    }

    public function testItUsesJsonSerializableRepresentationFromEnum(): void
    {
        $nodeJson = JsonValue::from(SerializableDirection::North);

        $this->assertInstanceOf(StringNode::class, $nodeJson);
        $this->assertSame('north', $nodeJson->value);
    }

    public function testItRejectsPureEnum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported JSON value.');

        JsonValue::from(PureDirection::North);
    }

    private function nestedArray(int $depth): mixed
    {
        $value = 0;

        for ($i = 0; $i < $depth; $i++) {
            $value = [$value];
        }

        return $value;
    }
}
