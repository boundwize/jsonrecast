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
use SplObjectStorage;
use stdClass;
use UnitEnum;

use function array_is_list;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;
use function preg_match;
use function str_starts_with;
use function strpbrk;

use const JSON_THROW_ON_ERROR;

final class JsonValue
{
    public static function from(mixed $value, int $maximumDepth = MaximumDepthGuard::DEFAULT_MAXIMUM_DEPTH): NodeJson
    {
        MaximumDepthGuard::validateMaximumDepth($maximumDepth);

        return self::fromValue($value, $maximumDepth, 0, false);
    }

    private static function fromValue(
        mixed $value,
        int $maximumDepth,
        int $depth,
        bool $allowPlainObjects
    ): NodeJson {
        return match (true) {
            is_string($value) => self::stringNode($value),
            is_float($value) && ! is_finite($value) => throw new InvalidArgumentException('Unsupported JSON value.'),
            is_int($value) => new NumberNode((string) $value),
            is_float($value) => new NumberNode(self::formatFloat($value)),
            is_bool($value) => new BooleanNode($value),
            $value === null => new NullNode(),
            is_array($value) => self::fromArray($value, $maximumDepth, $depth, $allowPlainObjects),
            $value instanceof JsonSerializable => self::fromJsonSerializable($value, $maximumDepth, $depth),
            $value instanceof BackedEnum => self::fromValue(
                $value->value,
                $maximumDepth,
                $depth,
                $allowPlainObjects,
            ),
            $value instanceof UnitEnum => throw new InvalidArgumentException('Unsupported JSON value.'),
            is_object($value) => self::fromObject($value, $maximumDepth, $depth, $allowPlainObjects),
            default => throw new InvalidArgumentException('Unsupported JSON value.'),
        };
    }

    private static function fromJsonSerializable(
        JsonSerializable $jsonSerializable,
        int $maximumDepth,
        int $depth
    ): NodeJson {
        // jsonSerialize() hops never consume a container nesting level and a
        // linear chain of them is not capped by maximumDepth, mirroring
        // json_encode(); hopping back to an object the chain already
        // serialized can never terminate and is rejected the way
        // json_encode() reports recursion. Whether a chain fabricating a
        // fresh object on every hop ever terminates is undecidable, and
        // json_encode() has no detection for it either -- it runs until
        // engine limits end it -- so such chains stay the caller's
        // responsibility here as well
        $chain = new SplObjectStorage();

        while (true) {
            $chain->attach($jsonSerializable);

            $serializedValue = $jsonSerializable->jsonSerialize();

            // json_encode() serializes the object's properties when jsonSerialize() returns $this
            if ($serializedValue === $jsonSerializable) {
                return self::fromObject($jsonSerializable, $maximumDepth, $depth, true);
            }

            if (! $serializedValue instanceof JsonSerializable) {
                return self::fromValue($serializedValue, $maximumDepth, $depth, true);
            }

            if ($chain->contains($serializedValue)) {
                throw new InvalidArgumentException('Recursion detected.');
            }

            $jsonSerializable = $serializedValue;
        }
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
    private static function fromArray(
        array $value,
        int $maximumDepth,
        int $depth,
        bool $allowPlainObjects
    ): NodeJson {
        // Match json_encode(): it only consumes a nesting level when entering
        // a container, so scalar leaves at the final depth are convertible.
        MaximumDepthGuard::guardMaximumDepth($maximumDepth, $depth);

        if (array_is_list($value)) {
            $items = [];

            foreach ($value as $item) {
                $items[] = new ArrayItemNode(
                    self::fromValue($item, $maximumDepth, $depth + 1, $allowPlainObjects),
                );
            }

            return new ArrayNode($items);
        }

        $items = [];

        foreach ($value as $key => $item) {
            $items[] = new ObjectItemNode(
                key: self::stringNode((string) $key),
                value: self::fromValue($item, $maximumDepth, $depth + 1, $allowPlainObjects),
            );
        }

        return new ObjectNode($items);
    }

    private static function fromObject(
        object $value,
        int $maximumDepth,
        int $depth,
        bool $allowPlainObjects
    ): ObjectNode {
        // Plain objects are supported only within the representation returned
        // by jsonSerialize(); direct conversion remains deliberately limited
        // to stdClass and jsonSerialize()-returns-$this objects.
        if (
            ! $allowPlainObjects
            && ! $value instanceof stdClass
            && ! $value instanceof JsonSerializable
        ) {
            throw new InvalidArgumentException('Unsupported JSON value.');
        }

        MaximumDepthGuard::guardMaximumDepth($maximumDepth, $depth);

        // Entering an accepted stdClass makes its whole representation
        // serializable, matching json_encode(); plain objects nested in it are
        // converted even though direct conversion of them stays rejected.
        $allowNestedPlainObjects = $allowPlainObjects || $value instanceof stdClass;

        $items = [];

        foreach (self::objectProperties($value) as $key => $item) {
            $items[] = new ObjectItemNode(
                key: self::stringNode((string) $key),
                value: self::fromValue($item, $maximumDepth, $depth + 1, $allowNestedPlainObjects),
            );
        }

        return new ObjectNode($items);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function objectProperties(object $value): array
    {
        // Internal objects such as ArrayObject or DateTime expose the
        // representation json_encode() serializes through a property handler
        // rather than through declared properties, so get_object_vars() reports
        // none of it; the array cast sees the same set json_encode() does.
        $properties = (array) $value;

        // Objects without an array representation, Closure among them, cast to
        // a list wrapping themselves; json_encode() emits {} for those.
        if (($properties[0] ?? null) === $value) {
            return [];
        }

        $publicProperties = [];

        foreach ($properties as $key => $item) {
            // The cast prefixes protected and private properties with a
            // NUL-delimited class marker; json_encode() leaves them out.
            if (is_string($key) && str_starts_with($key, "\0")) {
                continue;
            }

            $publicProperties[$key] = $item;
        }

        return $publicProperties;
    }
}
