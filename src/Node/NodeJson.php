<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

interface NodeJson
{
    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array;

    public function getAttribute(string $name): mixed;

    public function setAttribute(string $name, mixed $value): void;

    public function hasAttribute(string $name): bool;

    public function removeAttribute(string $name): void;
}
