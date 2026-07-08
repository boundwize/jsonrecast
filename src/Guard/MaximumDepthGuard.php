<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Guard;

use InvalidArgumentException;

final class MaximumDepthGuard
{
    public const DEFAULT_MAXIMUM_DEPTH = 512;

    public const EXCEEDED_MESSAGE = 'Maximum stack depth exceeded.';

    private function __construct()
    {
    }

    public static function validateMaximumDepth(int $maximumDepth): void
    {
        if ($maximumDepth < 1) {
            throw new InvalidArgumentException('Maximum depth must be greater than 0.');
        }
    }

    public static function isExceeded(int $maximumDepth, int $depth): bool
    {
        return $depth >= $maximumDepth;
    }

    public static function guardMaximumDepth(int $maximumDepth, int $depth): void
    {
        if (! self::isExceeded($maximumDepth, $depth)) {
            return;
        }

        throw new InvalidArgumentException(self::EXCEEDED_MESSAGE);
    }
}
