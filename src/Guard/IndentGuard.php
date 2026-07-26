<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Guard;

use InvalidArgumentException;

use function strlen;
use function strspn;

final class IndentGuard
{
    private function __construct()
    {
    }

    public static function validateIndent(string $indent): string
    {
        if (strspn($indent, " \t") !== strlen($indent)) {
            throw new InvalidArgumentException('Indent must contain only spaces or tabs.');
        }

        return $indent;
    }
}
