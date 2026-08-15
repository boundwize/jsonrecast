<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Value\Fixture;

use JsonSerializable;

final class CountdownSerializable implements JsonSerializable
{
    public function __construct(private readonly int $remaining)
    {
    }

    public function jsonSerialize(): mixed
    {
        return $this->remaining > 0 ? new self($this->remaining - 1) : 'launch';
    }
}
