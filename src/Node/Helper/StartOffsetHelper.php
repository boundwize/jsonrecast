<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node\Helper;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\NodeJson;

use function is_float;
use function is_int;

final readonly class StartOffsetHelper
{
    public static function getNumericStartOffset(NodeJson $nodeJson): ?float
    {
        $startOffset = $nodeJson->getAttribute(NodeAttributes::START_OFFSET);

        if (is_int($startOffset) || is_float($startOffset)) {
            return (float) $startOffset;
        }

        return null;
    }

    /**
     * @template T of NodeJson
     * @param list<T> $items
     * @return T|null
     */
    public static function findStyleDonor(array $items): ?NodeJson
    {
        $styleDonor     = null;
        $maxStartOffset = null;

        foreach ($items as $item) {
            $startOffset = self::getNumericStartOffset($item);

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
