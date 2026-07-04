<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node;

use Boundwize\JsonRecast\Attribute\NodeAttributes;

use function is_float;
use function is_int;

trait AppendedItemStyleDonorTrait
{
    private function numericStartOffsetOf(ArrayItemNode|ObjectItemNode $nodeJson): ?float
    {
        $startOffset = $nodeJson->getAttribute(NodeAttributes::START_OFFSET);

        if (is_int($startOffset) || is_float($startOffset)) {
            return (float) $startOffset;
        }

        return null;
    }

    /**
     * @template T of ArrayItemNode|ObjectItemNode
     * @param list<T> $items
     * @return T|null
     */
    private function styleDonorForAppendedItem(array $items): ArrayItemNode|ObjectItemNode|null
    {
        $styleDonor     = null;
        $maxStartOffset = null;

        foreach ($items as $item) {
            $startOffset = $this->numericStartOffsetOf($item);

            if ($startOffset === null) {
                continue;
            }

            if ($maxStartOffset === null || $startOffset > $maxStartOffset) {
                $maxStartOffset = $startOffset;
                $styleDonor     = $item;
            }
        }

        return $styleDonor;
    }
}
