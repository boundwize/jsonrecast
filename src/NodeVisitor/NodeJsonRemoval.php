<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\NodeVisitor;

final class NodeJsonRemoval
{
    private function __construct()
    {
    }

    public static function remove(): self
    {
        return new self();
    }
}
