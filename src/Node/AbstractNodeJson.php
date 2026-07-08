<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

use Boundwize\JsonRecast\Attribute\NodeAttributes;

use function array_key_exists;
use function is_string;

abstract class AbstractNodeJson implements NodeJson
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function removeAttribute(string $name): void
    {
        unset($this->attributes[$name]);
    }

    protected function indentForNewItem(): string
    {
        $indent = $this->getAttribute(NodeAttributes::INDENT);

        return is_string($indent) ? $indent : '    ';
    }
}
