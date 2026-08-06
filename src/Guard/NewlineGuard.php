<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Guard;

use InvalidArgumentException;

use function in_array;

final class NewlineGuard
{
    private function __construct()
    {
    }

    public static function validateNewline(string $newline): string
    {
        if (! in_array($newline, ["\n", "\r\n", "\r"], true)) {
            throw new InvalidArgumentException('Newline must be "\n", "\r\n", or "\r".');
        }

        return $newline;
    }
}
