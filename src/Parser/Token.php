<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $text,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly int $line,
        public readonly int $column,
    ) {
    }
}
