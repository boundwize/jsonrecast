<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer\Helper;

use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Printer\PrintContext;
use Closure;

use function count;

/**
 * Container shape shared by every printer: which delimiters a container is
 * written with, and how its items are laid out when the printer places them
 * itself instead of preserving source whitespace.
 *
 * @internal
 */
final readonly class ContainerPrintHelper
{
    public static function openingDelimiter(ArrayNode|ObjectNode $containerNode): string
    {
        return $containerNode instanceof ArrayNode ? '[' : '{';
    }

    public static function closingDelimiter(ArrayNode|ObjectNode $containerNode): string
    {
        return $containerNode instanceof ArrayNode ? ']' : '}';
    }

    /**
     * One item per line, each indented one level below the container and
     * separated by a comma, with the closing delimiter back on the container's
     * own indentation. Only the item text differs between printers, so the
     * caller supplies it; the child print context it receives is the one the
     * item is laid out at here.
     *
     * @param Closure(ArrayItemNode|ObjectItemNode, PrintContext, int): string $printItem
     */
    public static function printItemsOnOwnLines(
        ArrayNode|ObjectNode $containerNode,
        PrintContext $printContext,
        Closure $printItem,
    ): string {
        $output            = self::openingDelimiter($containerNode);
        $lastIndex         = count($containerNode->items) - 1;
        $childPrintContext = $printContext->next();
        $childIndentation  = $printContext->childIndentation();

        foreach ($containerNode->items as $i => $item) {
            $output .= $printContext->newline
                . $childIndentation
                . $printItem($item, $childPrintContext, $i);

            if ($i < $lastIndex) {
                $output .= ',';
            }
        }

        return $output
            . $printContext->newline
            . $printContext->indentation()
            . self::closingDelimiter($containerNode);
    }
}
