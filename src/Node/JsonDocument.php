<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

final class JsonDocument extends AbstractNodeJson
{
    public function __construct(
        public NodeJson $value,
    ) {
    }
}
