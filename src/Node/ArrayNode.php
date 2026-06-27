<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

use function array_key_exists;
use function array_splice;

final class ArrayNode extends AbstractNodeJson
{
    /**
     * @param list<ArrayItemNode> $items
     */
    public function __construct(
        public array $items,
        public string $afterOpenBracket = '',
        public string $beforeCloseBracket = '',
    ) {
    }

    public function append(NodeJson $value): void
    {
        $this->items[] = new ArrayItemNode($value);
    }

    public function insert(int $index, NodeJson $value): void
    {
        array_splice($this->items, $index, 0, [new ArrayItemNode($value)]);
    }

    public function removeAt(int $index): bool
    {
        if (! array_key_exists($index, $this->items)) {
            return false;
        }

        array_splice($this->items, $index, 1);

        return true;
    }
}
