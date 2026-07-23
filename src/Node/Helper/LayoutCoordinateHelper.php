<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Node\Helper;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\NodeJson;

use function is_int;
use function is_string;

/**
 * @internal
 */
final readonly class LayoutCoordinateHelper
{
    public static function setForNewItem(NodeJson $item, NodeJson $container, ?NodeJson $styleDonor): void
    {
        $depth = $styleDonor?->getAttribute(NodeAttributes::DEPTH);

        if (! is_int($depth)) {
            $containerDepth = $container->getAttribute(NodeAttributes::DEPTH);
            $depth          = is_int($containerDepth) ? $containerDepth + 1 : null;
        }

        if (is_int($depth)) {
            $item->setAttribute(NodeAttributes::DEPTH, $depth);
        }

        $indent = $styleDonor?->getAttribute(NodeAttributes::INDENT);

        if (! is_string($indent)) {
            $indent = $container->getAttribute(NodeAttributes::INDENT);
        }

        if (is_string($indent)) {
            $item->setAttribute(NodeAttributes::INDENT, $indent);
        }
    }
}
