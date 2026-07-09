<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Printer;

use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\JsonRecast\Parser\JsonParser;
use Boundwize\JsonRecast\Printer\JsonPreservingPrinter;
use Boundwize\JsonRecast\Value\JsonValue;
use PHPUnit\Framework\TestCase;

final class JsonPrinterTest extends TestCase
{
    public function testItPreservesSpacingAroundColon(): void
    {
        $source = <<<'JSON'
{
    "name"   :   "old"
}
JSON;

        $this->assertSame(<<<'JSON'
{
    "name"   :   "new"
}
JSON, $this->replaceStringValue($source, 'old', 'new'));
    }

    public function testItPreservesUnmodifiedObjectItem(): void
    {
        $source = <<<'JSON'
{
    "name"   :   "old",
    "type"   :   "library"
}
JSON;

        $this->assertSame(
            <<<'JSON'
{
    "name"   :   "new",
    "type"   :   "library"
}
JSON,
            $this->replaceStringValue($source, 'old', 'new'),
        );
    }

    public function testItPreservesNumberRawValue(): void
    {
        $source = '{"value": 1.0}';

        $this->assertSame($source, JsonRecast::print(JsonRecast::parse($source)));
    }

    public function testItPreservesExponentNumberRawValue(): void
    {
        $source = '{"value": 1E+0}';

        $this->assertSame($source, JsonRecast::print(JsonRecast::parse($source)));
    }

    public function testItPreservesNewlineStyle(): void
    {
        $source = "{\r\n    \"name\": \"old\"\r\n}\r\n";

        $this->assertSame("{\r\n    \"name\": \"new\"\r\n}\r\n", $this->replaceStringValue($source, 'old', 'new'));
    }

    public function testItPreservesArrayFormatting(): void
    {
        $source = <<<'JSON'
[
    "old",
    "keep"
]
JSON;

        $this->assertSame(<<<'JSON'
[
    "new",
    "keep"
]
JSON, $this->replaceStringValue($source, 'old', 'new'));
    }

    public function testItPreservesRecursiveObjectFormatting(): void
    {
        $source = <<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": "./"
        }
    },
    "type": "library"
}
JSON;

        $this->assertSame(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    },
    "type": "library"
}
JSON, $this->replaceStringValue($source, './', 'src/'));
    }

    public function testItPreservesRecursiveArrayFormatting(): void
    {
        $source = <<<'JSON'
[
    [
        [
            "old"
        ]
    ],
    "keep"
]
JSON;

        $this->assertSame(
            <<<'JSON'
[
    [
        [
            "new"
        ]
    ],
    "keep"
]
JSON,
            $this->replaceStringValue($source, 'old', 'new'),
        );
    }

    public function testItPreservesMixedRecursiveFormatting(): void
    {
        $source = <<<'JSON'
{
    "items": [
        {
            "name" : "old"
        }
    ],
    "type": "library"
}
JSON;

        $this->assertSame(<<<'JSON'
{
    "items": [
        {
            "name" : "new"
        }
    ],
    "type": "library"
}
JSON, $this->replaceStringValue($source, 'old', 'new'));
    }

    public function testItPreservesInPlaceScalarMutationWhenParentNodeIsReturned(): void
    {
        $jsonDocument = JsonRecast::parse('{"name":"old"}');

        $jsonRecastResult = JsonRecast::traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (
                    ! $nodeJson instanceof ObjectItemNode
                    || $nodeJson->key->value !== 'name'
                    || ! $nodeJson->value instanceof StringNode
                ) {
                    return null;
                }

                $nodeJson->value->value = 'new';

                return $nodeJson;
            }
        });

        $this->assertSame('{"name":"new"}', JsonRecast::print($jsonRecastResult));
    }

    public function testItPrintsObjectSetReplacementFromParsedNode(): void
    {
        $jsonDocument = JsonRecast::parse('{"a":"old","b":"new"}');

        $jsonRecastResult = JsonRecast::traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof ObjectNode || ! $nodeJsonPath->isRoot()) {
                    return null;
                }

                $b = $nodeJson->get('b');
                if (! $b instanceof ObjectItemNode) {
                    return null;
                }

                $nodeJson->set('a', $b->value);

                return $nodeJson;
            }
        });

        $this->assertSame('{"a":"new","b":"new"}', JsonRecast::print($jsonRecastResult));
    }

    public function testItPreservesAddObjectItemBestEffort(): void
    {
        $source = <<<'JSON'
{
    "name": "boundwize/jsonrecast"
}
JSON;

        $this->assertSame(
            <<<'JSON'
{
    "name": "boundwize/jsonrecast",
    "license": "MIT"
}
JSON,
            $this->addObjectItem($source),
        );
    }

    public function testItPreservesObjectItemColonSpacingWhenBestEffortReformatsContainer(): void
    {
        $parser       = new JsonParser();
        $jsonDocument = $parser->parse('{"a" :  1, "b":2}');
        $multiline    = $parser->parse(<<<'JSON'
{
    "x": 1,
    "y": 2
}
JSON);

        self::assertInstanceOf(ObjectNode::class, $jsonDocument->value);
        $jsonDocument->value->set('big', $multiline->value);

        $this->assertSame(
            <<<'JSON'
{
    "a" :  1,
    "b":2,
    "big":{
        "x": 1,
        "y": 2
    }
}
JSON,
            (new JsonPreservingPrinter())->print($jsonDocument),
        );
    }

    public function testItPreservesRemoveObjectItemBestEffort(): void
    {
        $source = <<<'JSON'
{
    "name": "boundwize/jsonrecast",
    "minimum-stability": "dev"
}
JSON;

        $this->assertSame(
            <<<'JSON'
{
    "name": "boundwize/jsonrecast"
}
JSON,
            $this->removeObjectItem($source, 'minimum-stability'),
        );
    }

    public function testItPreservesInlineObjectFormattingWhenRemovingObjectItem(): void
    {
        $source = <<<'JSON'
{
    "autoload": {
        "psr-4": {"Mixed\\": "src/", "Missing\\": "missing-tests/"}
    }
}
JSON;

        $this->assertSame(<<<'JSON'
{
    "autoload": {
        "psr-4": {"Mixed\\": "src/"}
    }
}
JSON, $this->removeObjectItem($source, 'Missing\\'));
    }

    public function testItRemovesEmptyStringObjectKey(): void
    {
        $source = <<<'JSON'
{
    "autoload": {
        "psr-4": {
            "": "./"
        }
    }
}
JSON;

        $this->assertSame(<<<'JSON'
{
    "autoload": {
        "psr-4": {
        }
    }
}
JSON, $this->removeObjectItem($source, ''));
    }

    public function testItPreservesAddArrayItemBestEffort(): void
    {
        $source = <<<'JSON'
[
    "json"
]
JSON;

        $this->assertSame(<<<'JSON'
[
    "json",
    "ast"
]
JSON, $this->appendArrayItem($source));
    }

    public function testItPreservesRemoveArrayItemBestEffort(): void
    {
        $source = <<<'JSON'
[
    "json",
    "temporary"
]
JSON;

        $this->assertSame(<<<'JSON'
[
    "json"
]
JSON, $this->removeArrayItem($source, 'temporary'));
    }

    public function testItPreservesInlineArrayFormattingWhenRemovingArrayItem(): void
    {
        $source = <<<'JSON'
{
    "autoload": {
        "psr-4": {
            "Mixed\\": ["src/", "missing-tests/"]
        }
    }
}
JSON;

        $this->assertSame(<<<'JSON'
{
    "autoload": {
        "psr-4": {
            "Mixed\\": ["src/"]
        }
    }
}
JSON, $this->removeArrayItem($source, 'missing-tests/'));
    }

    public function testItRemovesOnlyClassmapArrayItem(): void
    {
        $source = <<<'JSON'
{
    "autoload-dev": {
        "classmap": [
            "tests/Fixtures/App"
        ]
    }
}
JSON;

        $this->assertSame(<<<'JSON'
{
    "autoload-dev": {
        "classmap": [
        ]
    }
}
JSON, $this->removeArrayItem($source, 'tests/Fixtures/App'));
    }

    public function testItPrintsUnchangedDocumentWithoutChangeSet(): void
    {
        $source       = <<<'JSON'
{
    "name": "boundwize/jsonrecast"
}
JSON;
        $jsonDocument = (new JsonParser())->parse($source);

        $this->assertSame($source, (new JsonPreservingPrinter())->print($jsonDocument));
    }

    private function replaceStringValue(string $source, string $old, string $new): string
    {
        $jsonDocument = JsonRecast::parse($source);

        $jsonRecastResult = JsonRecast::traverse($jsonDocument, new class ($old, $new) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly string $old,
                private readonly string $new,
            ) {
            }

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof StringNode || $nodeJson->value !== $this->old) {
                    return null;
                }

                return new StringNode($this->new);
            }
        });

        return JsonRecast::print($jsonRecastResult);
    }

    private function addObjectItem(string $source): string
    {
        $jsonDocument = JsonRecast::parse($source);

        $jsonRecastResult = JsonRecast::traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof ObjectNode || ! $nodeJsonPath->isRoot()) {
                    return null;
                }

                $nodeJson->set('license', JsonValue::from('MIT'));

                return $nodeJson;
            }
        });

        return JsonRecast::print($jsonRecastResult);
    }

    private function removeObjectItem(string $source, string $key): string
    {
        $jsonDocument = JsonRecast::parse($source);

        $jsonRecastResult = JsonRecast::traverse($jsonDocument, new class ($key) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly string $key,
            ) {
            }

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if (! $nodeJson instanceof ObjectItemNode || $nodeJson->key->value !== $this->key) {
                    return null;
                }

                return NodeJsonVisitor::REMOVE_NODE;
            }
        });

        return JsonRecast::print($jsonRecastResult);
    }

    private function appendArrayItem(string $source): string
    {
        $jsonDocument = JsonRecast::parse($source);

        $jsonRecastResult = JsonRecast::traverse($jsonDocument, new class extends NodeJsonVisitorAbstract {
            public function leaveNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?NodeJson
            {
                if (! $nodeJson instanceof ArrayNode || ! $nodeJsonPath->isRoot()) {
                    return null;
                }

                $nodeJson->append(JsonValue::from('ast'));

                return $nodeJson;
            }
        });

        return JsonRecast::print($jsonRecastResult);
    }

    private function removeArrayItem(string $source, string $value): string
    {
        $jsonDocument = JsonRecast::parse($source);

        $jsonRecastResult = JsonRecast::traverse($jsonDocument, new class ($value) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly string $value,
            ) {
            }

            public function enterNode(NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): ?int
            {
                if (! $nodeJson instanceof ArrayItemNode || ! $nodeJson->value instanceof StringNode) {
                    return null;
                }

                if ($nodeJson->value->value !== $this->value) {
                    return null;
                }

                return NodeJsonVisitor::REMOVE_NODE;
            }
        });

        return JsonRecast::print($jsonRecastResult);
    }
}
