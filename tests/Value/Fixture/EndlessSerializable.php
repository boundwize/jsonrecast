<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Value\Fixture;

use JsonSerializable;

final class EndlessSerializable implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return new self();
    }
}
