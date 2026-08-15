<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Value\Fixture;

use JsonSerializable;

final readonly class CountdownSerializable implements JsonSerializable
{
    public function __construct(private int $remaining)
    {
    }

    public function jsonSerialize(): mixed
    {
        return $this->remaining > 0 ? new self($this->remaining - 1) : 'launch';
    }
}
