<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

use RuntimeException;

final class ParseError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $offset,
        public readonly int $sourceLine,
        public readonly int $column,
    ) {
        parent::__construct($message);
    }
}
