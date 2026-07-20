<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Value\Fixture;

use JsonSerializable;

final class SerializableLink implements JsonSerializable
{
    public function __construct(public mixed $next = null)
    {
    }

    public function jsonSerialize(): mixed
    {
        return $this->next;
    }
}
