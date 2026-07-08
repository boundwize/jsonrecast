<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests;

use Boundwize\JsonRecast\AstDumper;
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use PHPUnit\Framework\TestCase;

final class AstDumperTest extends TestCase
{
    public function testItDumpsParsedAst(): void
    {
        $jsonDocument = JsonRecast::parse(<<<'JSON'
{
    "name": "jsonrecast",
    "items": [
        1,
        true,
        null
    ]
}
JSON);

        $this->assertSame(
            <<<'TXT'
JsonDocument
└── value: ObjectNode (2 items)
    ├── [0]: ObjectItemNode
    │   ├── key: StringNode(value: "name")
    │   └── value: StringNode(value: "jsonrecast")
    └── [1]: ObjectItemNode
        ├── key: StringNode(value: "items")
        └── value: ArrayNode (3 items)
            ├── [0]: ArrayItemNode
            │   └── value: NumberNode(rawValue: "1")
            ├── [1]: ArrayItemNode
            │   └── value: BooleanNode(value: true)
            └── [2]: ArrayItemNode
                └── value: NullNode
TXT,
            JsonRecast::dumpAst($jsonDocument),
        );
    }

    public function testItDumpsTraversalResultDocument(): void
    {
        $jsonRecastResult = JsonRecast::traverse(JsonRecast::parse('"old"'), new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if ($nodeJson instanceof StringNode && $nodeJson->value === 'old') {
                    return new StringNode('new');
                }

                return null;
            }
        });

        $this->assertSame(
            <<<'TXT'
JsonDocument
└── value: StringNode(value: "new")
TXT,
            (new AstDumper())->dump($jsonRecastResult),
        );
    }

    public function testItCanIncludeAttributes(): void
    {
        $jsonDocument = JsonRecast::parse(<<<'JSON'
{
    "name" : "jsonrecast"
}

JSON);

        $this->assertSame(
            <<<'TXT'
JsonDocument
├── attributes
│   ├── startOffset: 0
│   ├── endOffset: 30
│   ├── depth: 0
│   ├── indent: "    "
│   ├── originalText: |
│   │   {
│   │       "name" : "jsonrecast"
│   │   }
│   ├── source: |
│   │   {
│   │       "name" : "jsonrecast"
│   │   }
│   ├── newline: "\n"
│   └── trailingNewline: true
└── value: ObjectNode (1 item)
    ├── attributes
    │   ├── startOffset: 0
    │   ├── endOffset: 29
    │   ├── depth: 0
    │   ├── indent: "    "
    │   └── originalText: |-
    │       {
    │           "name" : "jsonrecast"
    │       }
    └── [0]: ObjectItemNode
        ├── attributes
        │   ├── startOffset: 1
        │   ├── endOffset: 28
        │   ├── depth: 1
        │   ├── indent: "    "
        │   └── originalText: |
        │
        │           "name" : "jsonrecast"
        ├── key: StringNode(value: "name")
        │   └── attributes
        │       ├── startOffset: 6
        │       ├── endOffset: 12
        │       ├── depth: 1
        │       ├── indent: "    "
        │       └── originalText: "name"
        └── value: StringNode(value: "jsonrecast")
            └── attributes
                ├── startOffset: 15
                ├── endOffset: 27
                ├── depth: 1
                ├── indent: "    "
                └── originalText: "jsonrecast"
TXT,
            JsonRecast::dumpAst($jsonDocument, includeAttributes: true),
        );
    }
}
