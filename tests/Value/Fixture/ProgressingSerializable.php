<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Value\Fixture;

use JsonSerializable;

final class ProgressingSerializable implements JsonSerializable
{
    private static int $calls = 0;

    public function jsonSerialize(): mixed
    {
        return ++self::$calls < 4 ? new self() : 'done';
    }
}
