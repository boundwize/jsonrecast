<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Value;

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
use function strpbrk;

use const JSON_THROW_ON_ERROR;

final class JsonValue
{
    public static function from(mixed $value): NodeJson
    {
        return match (true) {
            is_string($value) => new StringNode($value),
            is_float($value) && ! is_finite($value) => throw new InvalidArgumentException('Unsupported JSON value.'),
            is_int($value) => new NumberNode((string) $value),
            is_float($value) => new NumberNode(self::formatFloat($value)),
            is_bool($value) => new BooleanNode($value),
            $value === null => new NullNode(),
            is_array($value) => self::fromArray($value),
            is_object($value) => self::fromObject($value),
            default => throw new InvalidArgumentException('Unsupported JSON value.'),
        };
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
    private static function fromArray(array $value): NodeJson
    {
        if (array_is_list($value)) {
            return new ArrayNode(array_map(
                static fn(mixed $item): ArrayItemNode => new ArrayItemNode(self::from($item)),
                $value,
            ));
        }

        $items = [];

        foreach ($value as $key => $item) {
            $items[] = new ObjectItemNode(
                key: new StringNode((string) $key),
                value: self::from($item),
            );
        }

        return new ObjectNode($items);
    }

    private static function fromObject(object $value): ObjectNode
    {
        $items = [];

        foreach (get_object_vars($value) as $key => $item) {
            $items[] = new ObjectItemNode(
                key: new StringNode((string) $key),
                value: self::from($item),
            );
        }

        return new ObjectNode($items);
    }
}
