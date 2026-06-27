<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests;

use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonRecastTest extends TestCase
{
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
