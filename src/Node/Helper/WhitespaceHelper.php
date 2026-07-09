<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node\Helper;

use function preg_match;

/**
 * @internal
 */
final readonly class WhitespaceHelper
{
    public static function closingLine(string $whitespace): string
    {
        if (preg_match('/(?:\r\n|\r|\n)[^\r\n]*$/', $whitespace, $matches) === 1) {
            return $matches[0];
        }

        return $whitespace;
    }
}
