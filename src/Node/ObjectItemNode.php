<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

final class ObjectItemNode extends AbstractNodeJson
{
    public function __construct(
        public StringNode $key,
        public NodeJson $value,
        public string $beforeKey = '',
        public string $betweenKeyAndColon = '',
        public string $betweenColonAndValue = '',
        public string $afterValue = '',
    ) {
    }
}
