<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodePath;

use function array_key_last;
use function count;
use function is_string;

final readonly class NodeJsonPath
{
    /**
     * @param list<NodeJsonPathSegment> $segments
     */
    public function __construct(
        private array $segments = [],
    ) {
    }

    public function childObjectKey(string $key): self
    {
        return new self([
            ...$this->segments,
            NodeJsonPathSegment::objectKey($key),
        ]);
    }

    public function childArrayIndex(int $index): self
    {
        return new self([
            ...$this->segments,
            NodeJsonPathSegment::arrayIndex($index),
        ]);
    }

    /**
     * @return list<NodeJsonPathSegment>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function isRoot(): bool
    {
        return $this->segments === [];
    }

    public function last(): ?NodeJsonPathSegment
    {
        $lastKey = array_key_last($this->segments);

        if ($lastKey === null) {
            return null;
        }

        return $this->segments[$lastKey];
    }

    public function depth(): int
    {
        return count($this->segments);
    }

    public function isObjectValue(string $key): bool
    {
        $last = $this->last();

        return $last instanceof NodeJsonPathSegment
            && $last->isObjectKey()
            && $last->value === $key;
    }

    public function isArrayValue(int $index): bool
    {
        $last = $this->last();

        return $last instanceof NodeJsonPathSegment
            && $last->isArrayIndex()
            && $last->value === $index;
    }

    /**
     * @param list<string> $keys
     */
    public function matchesObjectKeys(array $keys): bool
    {
        if (count($this->segments) !== count($keys)) {
            return false;
        }

        foreach ($this->segments as $i => $segment) {
            if (! $segment->isObjectKey()) {
                return false;
            }

            if ($segment->value !== $keys[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string|int> $segments
     */
    public function matches(array $segments): bool
    {
        if (count($this->segments) !== count($segments)) {
            return false;
        }

        foreach ($this->segments as $i => $segment) {
            $expected = $segments[$i];

            if (is_string($expected)) {
                if (! $segment->isObjectKey() || $segment->value !== $expected) {
                    return false;
                }

                continue;
            }

            if (! $segment->isArrayIndex() || $segment->value !== $expected) {
                return false;
            }
        }

        return true;
    }
}
