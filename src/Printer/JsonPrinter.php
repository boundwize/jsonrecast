<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer;

use Boundwize\JsonRecast\Node\NodeJson;

interface JsonPrinter
{
    public function print(NodeJson $node): string;
}
