<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast;

use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\NodeTraverser\NodeJsonTraverser;
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

    public static function traverse(JsonDocument $jsonDocument, NodeJsonVisitor $nodeJsonVisitor): JsonRecastResult
    {
        $nodeJsonTraverser = new NodeJsonTraverser();
        $nodeJsonTraverser->addVisitor($nodeJsonVisitor);

        $nodeJsonTraversalResult = $nodeJsonTraverser->traverse($jsonDocument);

        if (! $nodeJsonTraversalResult->node instanceof JsonDocument) {
            throw new RuntimeException('JsonRecast traversal must return JsonDocument.');
        }

        return new JsonRecastResult(
            document: $nodeJsonTraversalResult->node,
            changeSet: $nodeJsonTraversalResult->changeSet,
        );
    }

    public static function print(JsonRecastResult|JsonDocument $input): string
    {
        if ($input instanceof JsonRecastResult) {
            return (new JsonPreservingPrinter($input->changeSet))->print($input->document);
        }

        return (new JsonPreservingPrinter())->print($input);
    }

    public static function dumpAst(
        NodeJson|JsonRecastResult $input,
        bool $includeAttributes = false,
    ): string {
        return (new AstDumper(
            includeAttributes: $includeAttributes,
        ))->dump($input);
    }
}
