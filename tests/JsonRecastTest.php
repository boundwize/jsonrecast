<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests;

use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonRecastTest extends TestCase
{
    public function testReadmeExampleAddsEditsDeletesAndRemovesEmptyParent(): void
    {
        $json = <<<'JSON'
{
    "name": "acme/demo",
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "autoload-dev": {
        "classmap": [
            "tests/Fixtures/App"
        ]
    },
    "minimum-stability": "dev"
}
JSON;

        $jsonDocument = JsonRecast::parse($json);

        $jsonRecastResult = JsonRecast::traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|NodeJson|int
            {
                if ($nodeJson instanceof ObjectItemNode && $nodeJsonPath->isRoot()) {
                    if ($nodeJson->key->value === 'name') {
                        $nodeJson->value = new StringNode('boundwize/jsonrecast');

                        return $nodeJson;
                    }

                    if ($nodeJson->key->value === 'minimum-stability') {
                        return NodeJsonVisitor::REMOVE_NODE;
                    }
                }

                if ($nodeJson instanceof ObjectNode && $nodeJsonPath->matches(['autoload', 'psr-4'])) {
                    $nodeJson->set('Boundwize\\JsonRecast\\', new StringNode('src/'));

                    return $nodeJson;
                }

                if ($nodeJson instanceof ArrayNode && $nodeJsonPath->matches(['autoload-dev', 'classmap'])) {
                    $removed = false;

                    foreach ($nodeJson->items as $index => $item) {
                        if (
                            ! $item->value instanceof StringNode
                            || $item->value->value !== 'tests/Fixtures/App'
                        ) {
                            continue;
                        }

                        $nodeJson->removeAt($index);
                        $removed = true;
                    }

                    return $removed ? $nodeJson : null;
                }

                return null;
            }

            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if (
                    ! $nodeJson instanceof ObjectItemNode
                    || ! $nodeJsonPath->isRoot()
                    || $nodeJson->key->value !== 'autoload-dev'
                    || ! $nodeJson->value instanceof ObjectNode
                ) {
                    return null;
                }

                $classmapItem = $nodeJson->value->get('classmap');

                if (
                    ! $classmapItem instanceof ObjectItemNode
                    || ! $classmapItem->value instanceof ArrayNode
                    || $classmapItem->value->items !== []
                ) {
                    return null;
                }

                return NodeJsonVisitor::REMOVE_NODE;
            }
        });

        $this->assertSame(<<<'JSON'
{
    "name": "boundwize/jsonrecast",
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Boundwize\\JsonRecast\\": "src/"
        }
    }
}
JSON, JsonRecast::print($jsonRecastResult));
    }

    public function testTraverseRequiresDocumentResult(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JsonRecast traversal must return JsonDocument.');

        JsonRecast::traverse(JsonRecast::parse('"old"'), new class extends NodeJsonVisitorAbstract {
            public function beforeTraverse(NodeJson $nodeJson): NodeJson
            {
                return new StringNode('new');
            }
        });
    }

    public function testChangedDocumentPreservesRootWhitespace(): void
    {
        $jsonRecastResult = JsonRecast::traverse(
            JsonRecast::parse(" \n{\"name\": \"old\"}\n "),
            new class extends NodeJsonVisitorAbstract {
                public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
                {
                    if ($nodeJson instanceof StringNode && $nodeJson->value === 'old') {
                        return new StringNode('new');
                    }

                    return null;
                }
            },
        );

        $this->assertSame(" \n{\"name\": \"new\"}\n ", JsonRecast::print($jsonRecastResult));
    }
}
