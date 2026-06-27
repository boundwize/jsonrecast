<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

final class StringNode extends AbstractNodeJson
{
    public function __construct(
        public string $value,
    ) {
    }
}
