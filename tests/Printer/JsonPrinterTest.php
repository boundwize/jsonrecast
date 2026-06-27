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
use Boundwize\JsonRecast\NodeVisitor\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonRemoval;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\JsonRecast\Parser\JsonParser;
use Boundwize\JsonRecast\Printer\JsonPreservingPrinter;
use Boundwize\JsonRecast\Value\JsonValue;
use PHPUnit\Framework\TestCase;

final class JsonPrinterTest extends TestCase
{
    public function testItPreservesSpacingAroundColon(): void
    {
        $source = "{\n    \"name\"   :   \"old\"\n}";

        self::assertSame("{\n    \"name\"   :   \"new\"\n}", $this->replaceStringValue($source, 'old', 'new'));
    }

    public function testItPreservesUnmodifiedObjectItem(): void
    {
        $source = "{\n    \"name\"   :   \"old\",\n    \"type\"   :   \"library\"\n}";

        self::assertSame(
            "{\n    \"name\"   :   \"new\",\n    \"type\"   :   \"library\"\n}",
            $this->replaceStringValue($source, 'old', 'new'),
        );
    }

    public function testItPreservesNumberRawValue(): void
    {
        $source = '{"value": 1.0}';

        self::assertSame($source, JsonRecast::print(JsonRecast::parse($source)));
    }

    public function testItPreservesExponentNumberRawValue(): void
    {
        $source = '{"value": 1E+0}';

        self::assertSame($source, JsonRecast::print(JsonRecast::parse($source)));
    }

    public function testItPreservesNewlineStyle(): void
    {
        $source = "{\r\n    \"name\": \"old\"\r\n}\r\n";

        self::assertSame(
            "{\r\n    \"name\": \"new\"\r\n}\r\n",
            $this->replaceStringValue($source, 'old', 'new'),
        );
    }

    public function testItPreservesArrayFormatting(): void
    {
        $source = "[\n    \"old\",\n    \"keep\"\n]";

        self::assertSame("[\n    \"new\",\n    \"keep\"\n]", $this->replaceStringValue($source, 'old', 'new'));
    }

    public function testItPreservesRecursiveObjectFormatting(): void
    {
        $source = "{\n"
            . "    \"autoload\": {\n"
            . "        \"psr-4\": {\n"
            . "            \"App\\\\\": \"./\"\n"
            . "        }\n"
            . "    },\n"
            . "    \"type\": \"library\"\n"
            . '}';

        self::assertSame(
            "{\n"
                . "    \"autoload\": {\n"
                . "        \"psr-4\": {\n"
                . "            \"App\\\\\": \"src/\"\n"
                . "        }\n"
                . "    },\n"
                . "    \"type\": \"library\"\n"
                . '}',
            $this->replaceStringValue($source, './', 'src/'),
        );
    }

    public function testItPreservesRecursiveArrayFormatting(): void
    {
        $source = "[\n    [\n        [\n            \"old\"\n        ]\n    ],\n    \"keep\"\n]";

        self::assertSame(
            "[\n    [\n        [\n            \"new\"\n        ]\n    ],\n    \"keep\"\n]",
            $this->replaceStringValue($source, 'old', 'new'),
        );
    }

    public function testItPreservesMixedRecursiveFormatting(): void
    {
        $source = "{\n"
            . "    \"items\": [\n"
            . "        {\n"
            . "            \"name\" : \"old\"\n"
            . "        }\n"
            . "    ],\n"
            . "    \"type\": \"library\"\n"
            . '}';

        self::assertSame(
            "{\n"
                . "    \"items\": [\n"
                . "        {\n"
                . "            \"name\" : \"new\"\n"
                . "        }\n"
                . "    ],\n"
                . "    \"type\": \"library\"\n"
                . '}',
            $this->replaceStringValue($source, 'old', 'new'),
        );
    }

    public function testItPreservesAddObjectItemBestEffort(): void
    {
        $source = "{\n    \"name\": \"boundwize/jsonrecast\"\n}";

        self::assertSame(
            "{\n    \"name\": \"boundwize/jsonrecast\",\n    \"license\": \"MIT\"\n}",
            $this->addObjectItem($source),
        );
    }

    public function testItPreservesRemoveObjectItemBestEffort(): void
    {
        $source = "{\n    \"name\": \"boundwize/jsonrecast\",\n    \"minimum-stability\": \"dev\"\n}";

        self::assertSame(
            "{\n    \"name\": \"boundwize/jsonrecast\"\n}",
            $this->removeObjectItem($source, 'minimum-stability'),
        );
    }

    public function testItPreservesAddArrayItemBestEffort(): void
    {
        $source = "[\n    \"json\"\n]";

        self::assertSame("[\n    \"json\",\n    \"ast\"\n]", $this->appendArrayItem($source));
    }

    public function testItPreservesRemoveArrayItemBestEffort(): void
    {
        $source = "[\n    \"json\",\n    \"temporary\"\n]";

        self::assertSame("[\n    \"json\"\n]", $this->removeArrayItem($source, 'temporary'));
    }

    public function testItPrintsUnchangedDocumentWithoutChangeSet(): void
    {
        $source   = "{\n    \"name\": \"boundwize/jsonrecast\"\n}";
        $document = (new JsonParser())->parse($source);

        self::assertSame($source, (new JsonPreservingPrinter())->print($document));
    }

    private function replaceStringValue(string $source, string $old, string $new): string
    {
        $document = JsonRecast::parse($source);

        $result = JsonRecast::traverse($document, new class ($old, $new) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly string $old,
                private readonly string $new,
            ) {
            }

            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if (! $node instanceof StringNode || $node->value !== $this->old) {
                    return null;
                }

                return new StringNode($this->new);
            }
        });

        return JsonRecast::print($result);
    }

    private function addObjectItem(string $source): string
    {
        $document = JsonRecast::parse($source);

        $result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
            public function leaveNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if (! $node instanceof ObjectNode || ! $path->isRoot()) {
                    return null;
                }

                $node->set('license', JsonValue::from('MIT'));

                return $node;
            }
        });

        return JsonRecast::print($result);
    }

    private function removeObjectItem(string $source, string $key): string
    {
        $document = JsonRecast::parse($source);

        $result = JsonRecast::traverse($document, new class ($key) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly string $key,
            ) {
            }

            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJsonRemoval
            {
                if (! $node instanceof ObjectItemNode || $node->key->value !== $this->key) {
                    return null;
                }

                return NodeJsonRemoval::remove();
            }
        });

        return JsonRecast::print($result);
    }

    private function appendArrayItem(string $source): string
    {
        $document = JsonRecast::parse($source);

        $result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
            public function leaveNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
            {
                if (! $node instanceof ArrayNode || ! $path->isRoot()) {
                    return null;
                }

                $node->append(JsonValue::from('ast'));

                return $node;
            }
        });

        return JsonRecast::print($result);
    }

    private function removeArrayItem(string $source, string $value): string
    {
        $document = JsonRecast::parse($source);

        $result = JsonRecast::traverse($document, new class ($value) extends NodeJsonVisitorAbstract {
            public function __construct(
                private readonly string $value,
            ) {
            }

            public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJsonRemoval
            {
                if (! $node instanceof ArrayItemNode || ! $node->value instanceof StringNode) {
                    return null;
                }

                if ($node->value->value !== $this->value) {
                    return null;
                }

                return NodeJsonRemoval::remove();
            }
        });

        return JsonRecast::print($result);
    }
}
