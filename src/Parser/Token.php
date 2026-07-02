<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

final readonly class Token
{
    /**
     * @param TokenType::* $type
     */
    public function __construct(
        public string $type,
        public string $text,
        public int $startOffset,
        public int $endOffset,
        public int $line,
        public int $column,
    ) {
    }
}
