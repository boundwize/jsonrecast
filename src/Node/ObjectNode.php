<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

use Boundwize\JsonRecast\Attribute\NodeAttributes;

use function array_splice;

final class ObjectNode extends AbstractNodeJson
{
    /**
     * @param list<ObjectItemNode> $items
     */
    public function __construct(
        public array $items,
        public string $afterOpenBrace = '',
        public string $beforeCloseBrace = '',
    ) {
    }

    public function get(string $key): ?ObjectItemNode
    {
        foreach ($this->items as $item) {
            if ($item->key->value === $key) {
                return $item;
            }
        }

        return null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) instanceof ObjectItemNode;
    }

    public function set(string $key, NodeJson $nodeJson): void
    {
        foreach ($this->items as $item) {
            if ($item->key->value !== $key) {
                continue;
            }

            $item->value = $nodeJson;
            $item->setAttribute(NodeAttributes::ORIGINAL_TEXT, null);

            return;
        }

        $this->items[] = new ObjectItemNode(
            key: new StringNode($key),
            value: $nodeJson,
        );
    }

    public function remove(string $key): bool
    {
        foreach ($this->items as $i => $item) {
            if ($item->key->value !== $key) {
                continue;
            }

            array_splice($this->items, $i, 1);

            return true;
        }

        return false;
    }
}
