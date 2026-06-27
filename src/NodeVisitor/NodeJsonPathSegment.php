<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

final readonly class NodeJsonPathSegment
{
    private const OBJECT_KEY = 'objectKey';

    private const ARRAY_INDEX = 'arrayIndex';

    private function __construct(
        private string $type,
        public string|int $value,
    ) {
    }

    public static function objectKey(string $key): self
    {
        return new self(self::OBJECT_KEY, $key);
    }

    public static function arrayIndex(int $index): self
    {
        return new self(self::ARRAY_INDEX, $index);
    }

    public function isObjectKey(): bool
    {
        return $this->type === self::OBJECT_KEY;
    }

    public function isArrayIndex(): bool
    {
        return $this->type === self::ARRAY_INDEX;
    }
}
