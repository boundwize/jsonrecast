<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Parser;

final readonly class NumberLexemeScanResult
{
    /**
     * @param int $endOffset offset just past the scanned number on success,
     *                       or the offset of the offending character on failure
     */
    public function __construct(
        public int $endOffset,
        public ?string $errorMessage = null,
    ) {
    }
}
