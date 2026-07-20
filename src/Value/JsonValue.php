<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Value;

use BackedEnum;
use Boundwize\JsonRecast\Guard\MaximumDepthGuard;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use InvalidArgumentException;
use JsonSerializable;
use UnitEnum;

use function array_is_list;
use function array_map;
use function get_object_vars;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;
use function preg_match;
use function strpbrk;

use const JSON_THROW_ON_ERROR;

final class JsonValue
{
    public static function from(mixed $value, int $maximumDepth = MaximumDepthGuard::DEFAULT_MAXIMUM_DEPTH): NodeJson
    {
        MaximumDepthGuard::validateMaximumDepth($maximumDepth);

        return self::fromValue($value, $maximumDepth, 0);
    }

    private static function fromValue(mixed $value, int $maximumDepth, int $depth): NodeJson
    {
        MaximumDepthGuard::guardMaximumDepth($maximumDepth, $depth);

        return match (true) {
            is_string($value) => self::stringNode($value),
            is_float($value) && ! is_finite($value) => throw new InvalidArgumentException('Unsupported JSON value.'),
            is_int($value) => new NumberNode((string) $value),
            is_float($value) => new NumberNode(self::formatFloat($value)),
            is_bool($value) => new BooleanNode($value),
            $value === null => new NullNode(),
            is_array($value) => self::fromArray($value, $maximumDepth, $depth),
            $value instanceof JsonSerializable => self::fromJsonSerializable($value, $maximumDepth, $depth),
            $value instanceof BackedEnum => self::fromValue($value->value, $maximumDepth, $depth),
            $value instanceof UnitEnum => throw new InvalidArgumentException('Unsupported JSON value.'),
            is_object($value) => self::fromObject($value, $maximumDepth, $depth),
            default => throw new InvalidArgumentException('Unsupported JSON value.'),
        };
    }

    private static function fromJsonSerializable(
        JsonSerializable $jsonSerializable,
        int $maximumDepth,
        int $depth
    ): NodeJson {
        $serializedValue = $jsonSerializable->jsonSerialize();

        // json_encode() serializes the object's properties when jsonSerialize() returns $this
        if ($serializedValue === $jsonSerializable) {
            return self::fromObject($jsonSerializable, $maximumDepth, $depth);
        }

        // count the hop as a level so cyclic or endless jsonSerialize() chains hit the depth guard
        return self::fromValue($serializedValue, $maximumDepth, $depth + 1);
    }

    private static function stringNode(string $value): StringNode
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('String value is not valid UTF-8.');
        }

        return new StringNode($value);
    }

    private static function formatFloat(float $value): string
    {
        $rawValue = json_encode($value, JSON_THROW_ON_ERROR);

        if (strpbrk($rawValue, '.eE') !== false) {
            return $rawValue;
        }

        return $rawValue . '.0';
    }

    /**
     * @param array<mixed> $value
     */
    private static function fromArray(array $value, int $maximumDepth, int $depth): NodeJson
    {
        if (array_is_list($value)) {
            return new ArrayNode(array_map(
                static fn(mixed $item): ArrayItemNode => new ArrayItemNode(
                    self::fromValue($item, $maximumDepth, $depth + 1),
                ),
                $value,
            ));
        }

        $items = [];

        foreach ($value as $key => $item) {
            $items[] = new ObjectItemNode(
                key: self::stringNode((string) $key),
                value: self::fromValue($item, $maximumDepth, $depth + 1),
            );
        }

        return new ObjectNode($items);
    }

    private static function fromObject(object $value, int $maximumDepth, int $depth): ObjectNode
    {
        $items = [];

        foreach (get_object_vars($value) as $key => $item) {
            $items[] = new ObjectItemNode(
                key: self::stringNode((string) $key),
                value: self::fromValue($item, $maximumDepth, $depth + 1),
            );
        }

        return new ObjectNode($items);
    }
}
