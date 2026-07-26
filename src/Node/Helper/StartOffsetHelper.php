<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node\Helper;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\NodeJson;

use function is_float;
use function is_int;

/**
 * @internal
 */
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

    /**
     * @template T of NodeJson
     * @param list<T> $items
     * @return T|null
     */
    public static function findPreviousStyleDonor(array $items): ?NodeJson
    {
        $styleDonor = self::findStyleDonor($items);

        if (! $styleDonor instanceof NodeJson) {
            return null;
        }

        $styleDonorStartOffset = self::getNumericStartOffset($styleDonor);

        if ($styleDonorStartOffset === null) {
            return null;
        }

        return self::findStyleDonorBefore($items, $styleDonorStartOffset);
    }

    /**
     * @template T of NodeJson
     * @param list<T> $items
     * @return T|null
     */
    public static function findStyleDonorBefore(array $items, float $beforeStartOffset): ?NodeJson
    {
        $previousStyleDonor  = null;
        $previousStartOffset = null;

        foreach ($items as $item) {
            $startOffset = self::getNumericStartOffset($item);

            if (
                $startOffset === null
                || $startOffset >= $beforeStartOffset
            ) {
                continue;
            }

            if ($previousStartOffset === null || $startOffset > $previousStartOffset) {
                $previousStartOffset = $startOffset;
                $previousStyleDonor  = $item;
            }
        }

        return $previousStyleDonor;
    }

    /**
     * @template T of NodeJson
     * @param list<T> $items
     * @return T|null
     */
    public static function findStyleDonorAfter(array $items, float $afterStartOffset): ?NodeJson
    {
        $nextStyleDonor  = null;
        $nextStartOffset = null;

        foreach ($items as $item) {
            $startOffset = self::getNumericStartOffset($item);

            if (
                $startOffset === null
                || $startOffset <= $afterStartOffset
            ) {
                continue;
            }

            if ($nextStartOffset === null || $startOffset < $nextStartOffset) {
                $nextStartOffset = $startOffset;
                $nextStyleDonor  = $item;
            }
        }

        return $nextStyleDonor;
    }
}
