<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests;

use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonRemoval;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\JsonRecast\Value\JsonValue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function count;

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
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): null|NodeJson|NodeJsonRemoval
            {
                if ($nodeJson instanceof ObjectItemNode && $nodeJsonPath->isRoot()) {
                    if ($nodeJson->key->value === 'name') {
                        $nodeJson->value = new StringNode('boundwize/jsonrecast');

                        return $nodeJson;
                    }

                    if ($nodeJson->key->value === 'minimum-stability') {
                        return NodeJsonRemoval::remove();
                    }
                }

                if ($nodeJson instanceof ObjectNode && $nodeJsonPath->matches(['autoload', 'psr-4'])) {
                    $nodeJson->set('Boundwize\\JsonRecast\\', JsonValue::from('src/'));

                    return $nodeJson;
                }

                if ($nodeJson instanceof ArrayNode && $nodeJsonPath->matches(['autoload-dev', 'classmap'])) {
                    $removed = false;

                    for ($i = count($nodeJson->items) - 1; $i >= 0; $i--) {
                        $item = $nodeJson->items[$i];

                        if (
                            ! $item->value instanceof StringNode
                            || $item->value->value !== 'tests/Fixtures/App'
                        ) {
                            continue;
                        }

                        $nodeJson->removeAt($i);
                        $removed = true;
                    }

                    return $removed ? $nodeJson : null;
                }

                return null;
            }

            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJsonRemoval
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

                return NodeJsonRemoval::remove();
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
}
