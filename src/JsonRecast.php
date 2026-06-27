<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast;

use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonTraverser;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\Parser\JsonParser;
use Boundwize\JsonRecast\Printer\JsonPreservingPrinter;
use RuntimeException;

final class JsonRecast
{
    public static function parse(string $source): JsonDocument
    {
        return (new JsonParser())->parse($source);
    }

    public static function traverse(JsonDocument $document, NodeJsonVisitor $visitor): JsonRecastResult
    {
        $traverser = new NodeJsonTraverser();
        $traverser->addVisitor($visitor);

        $result = $traverser->traverse($document);

        if (! $result->node instanceof JsonDocument) {
            throw new RuntimeException('JsonRecast traversal must return JsonDocument.');
        }

        return new JsonRecastResult(
            document: $result->node,
            changeSet: $result->changeSet,
        );
    }

    public static function print(JsonRecastResult|JsonDocument $input): string
    {
        if ($input instanceof JsonRecastResult) {
            return (new JsonPreservingPrinter($input->changeSet))->print($input->document);
        }

        return (new JsonPreservingPrinter())->print($input);
    }
}
