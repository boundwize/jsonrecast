<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

final class BooleanNode extends AbstractNodeJson
{
    public function __construct(
        public bool $value,
    ) {
    }
}
