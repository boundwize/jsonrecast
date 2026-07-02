<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

use Boundwize\JsonRecast\Attribute\NodeAttributes;

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

    public function append(NodeJson $nodeJson): void
    {
        $this->items[] = new ArrayItemNode($nodeJson);
    }

    public function insert(int $index, NodeJson $nodeJson): void
    {
        array_splice($this->items, $index, 0, [new ArrayItemNode($nodeJson)]);
    }

    public function setAt(int $index, NodeJson $nodeJson): bool
    {
        if (! array_key_exists($index, $this->items)) {
            return false;
        }

        $this->items[$index]->value = $nodeJson;
        $this->items[$index]->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);

        return true;
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
