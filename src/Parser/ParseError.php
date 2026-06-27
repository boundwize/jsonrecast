<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

use RuntimeException;

final class ParseError extends RuntimeException
{
    public readonly int $sourceLine;

    public function __construct(
        string $message,
        public readonly int $offset,
        int $line,
        public readonly int $column,
    ) {
        $this->sourceLine = $line;

        parent::__construct($message);
    }
}
