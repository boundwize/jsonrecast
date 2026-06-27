<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

final class ArrayItemNode extends AbstractNodeJson
{
    public function __construct(
        public NodeJson $value,
        public string $beforeValue = '',
        public string $afterValue = '',
    ) {
    }
}
