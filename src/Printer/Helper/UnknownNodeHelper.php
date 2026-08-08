<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer\Helper;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\NodeJson;
use RuntimeException;

use function is_string;
use function json_decode;
use function json_last_error;
use function max;

use const JSON_ERROR_NONE;

/**
 * NodeJson is a public interface, so a node of a kind outside the built-in set
 * can reach any printer. Such a node exposes no structure to assemble text
 * from, leaving every printer the same single option — emit its recorded text
 * verbatim into the value position it occupies — and therefore the same bar for
 * refusing it. Kept here so the printers cannot drift apart on which trees they
 * accept.
 *
 * @internal
 */
final readonly class UnknownNodeHelper
{
    /**
     * Text that is not a JSON value cannot stand for the node, and how deep the
     * node sits is part of that question: the tree guard cannot see inside such
     * a node, so its text is the one way a printable tree can outgrow the
     * maximum depth it must stay parseable at.
     *
     * @param positive-int $maximumDepth
     */
    public static function valueText(NodeJson $nodeJson, int $maximumDepth, int $depth): string
    {
        $originalText = $nodeJson->getAttribute(NodeAttributes::ORIGINAL_TEXT);

        if (! is_string($originalText) || ! self::isJsonValueText($originalText, $maximumDepth, $depth)) {
            throw new RuntimeException('Unsupported JSON node.');
        }

        return $originalText;
    }

    private static function isJsonValueText(string $text, int $maximumDepth, int $depth): bool
    {
        // Text printed at $depth nests that far below the document root already,
        // so it may only spend what the maximum depth has left over there. The
        // subtraction stays positive for every position the tree guard admits;
        // the clamp keeps json_decode() out of its rejected-depth range anyway.
        $remainingDepth = max(1, $maximumDepth - $depth);

        // json_decode() returns null both for the "null" literal and for
        // invalid text, so the error state is what separates them.
        json_decode($text, true, $remainingDepth);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
