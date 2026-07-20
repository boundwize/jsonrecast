<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Value\Fixture;

use JsonSerializable;

enum SerializableDirection implements JsonSerializable
{
    case North;

    public function jsonSerialize(): string
    {
        return 'north';
    }
}
