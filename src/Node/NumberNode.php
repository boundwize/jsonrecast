<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

use function str_contains;
use function stripos;

final class NumberNode extends AbstractNodeJson
{
    public function __construct(
        public string $rawValue,
    ) {
    }

    public function toIntOrFloat(): int|float
    {
        if (str_contains($this->rawValue, '.') || stripos($this->rawValue, 'e') !== false) {
            return (float) $this->rawValue;
        }

        $intValue = (int) $this->rawValue;

        if ((string) $intValue === $this->rawValue || ($this->rawValue === '-0' && $intValue === 0)) {
            return $intValue;
        }

        return (float) $this->rawValue;
    }
}
