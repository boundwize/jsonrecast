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

        // The cast round-trip also rejects "-0", because casting it to an
        // integer and back produces "0". It therefore stays a signed float.
        if ((string) $intValue === $this->rawValue) {
            return $intValue;
        }

        return (float) $this->rawValue;
    }
}
