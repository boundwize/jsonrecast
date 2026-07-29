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

        // the cast round trip fails for out-of-range integers and for "-0"
        // ((string) 0 is "0"), both of which must stay float
        if ((string) $intValue === $this->rawValue) {
            return $intValue;
        }

        return (float) $this->rawValue;
    }
}
