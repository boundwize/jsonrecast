<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests;

use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeJsonFinder;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\Parser\JsonParser;
use PHPUnit\Framework\TestCase;

use function array_map;

final class NodeJsonFinderTest extends TestCase
{
    private const COMPOSER_JSON = <<<'JSON'
{
    "name": "acme/demo",
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "keywords": ["json", "ast"],
    "count": 2
}
JSON;

    public function testFindReturnsAllMatchingNodes(): void
    {
        $jsonDocument = (new JsonParser())->parse(self::COMPOSER_JSON);

        $foundNodes = (new NodeJsonFinder())->find(
            $jsonDocument,
            static fn (NodeJson $nodeJson): bool => $nodeJson instanceof ObjectNode,
        );

        $this->assertCount(3, $foundNodes);
        $this->assertContainsOnlyInstancesOf(ObjectNode::class, $foundNodes);
    }

    public function testFindReceivesNodeJsonPath(): void
    {
        $jsonDocument = (new JsonParser())->parse(self::COMPOSER_JSON);

        $foundNodes = (new NodeJsonFinder())->find(
            $jsonDocument,
            static fn (NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): bool => $nodeJson instanceof StringNode
                && $nodeJsonPath->matches(['keywords', 0]),
        );

        $this->assertCount(1, $foundNodes);
        $this->assertInstanceOf(StringNode::class, $foundNodes[0]);
        $this->assertSame('json', $foundNodes[0]->value);
    }

    public function testFindReturnsEmptyListWhenNothingMatches(): void
    {
        $jsonDocument = (new JsonParser())->parse(self::COMPOSER_JSON);

        $foundNodes = (new NodeJsonFinder())->find(
            $jsonDocument,
            static fn (NodeJson $nodeJson): bool => $nodeJson instanceof BooleanNode,
        );

        $this->assertSame([], $foundNodes);
    }

    public function testFindFirstReturnsFirstMatchInTraversalOrder(): void
    {
        $jsonDocument = (new JsonParser())->parse(self::COMPOSER_JSON);

        $foundNode = (new NodeJsonFinder())->findFirst(
            $jsonDocument,
            static fn (NodeJson $nodeJson): bool => $nodeJson instanceof StringNode,
        );

        $this->assertInstanceOf(StringNode::class, $foundNode);
        $this->assertSame('name', $foundNode->value);
    }

    public function testFindFirstByPath(): void
    {
        $jsonDocument = (new JsonParser())->parse(self::COMPOSER_JSON);

        $foundNode = (new NodeJsonFinder())->findFirst(
            $jsonDocument,
            static fn (NodeJson $nodeJson, NodeJsonPath $nodeJsonPath): bool => $nodeJson instanceof ObjectNode
                && $nodeJsonPath->matches(['autoload', 'psr-4']),
        );

        $this->assertInstanceOf(ObjectNode::class, $foundNode);
        $this->assertSame(
            ['App\\'],
            array_map(
                static fn (ObjectItemNode $objectItemNode): string => $objectItemNode->key->value,
                $foundNode->items,
            ),
        );
    }

    public function testFindFirstStopsTraversalAfterMatch(): void
    {
        $jsonDocument = (new JsonParser())->parse(self::COMPOSER_JSON);
        $filterCalls  = 0;

        $foundNode = (new NodeJsonFinder())->findFirst(
            $jsonDocument,
            static function (NodeJson $nodeJson) use (&$filterCalls): bool {
                $filterCalls++;

                return $nodeJson instanceof StringNode;
            },
        );

        $this->assertInstanceOf(StringNode::class, $foundNode);
        $this->assertSame('name', $foundNode->value);
        // JsonDocument, root ObjectNode, first ObjectItemNode, then the "name" key matches
        $this->assertSame(4, $filterCalls);
    }

    public function testFindFirstReturnsNullWhenNothingMatches(): void
    {
        $jsonDocument = (new JsonParser())->parse(self::COMPOSER_JSON);

        $foundNode = (new NodeJsonFinder())->findFirst(
            $jsonDocument,
            static fn (NodeJson $nodeJson): bool => $nodeJson instanceof BooleanNode,
        );

        $this->assertNotInstanceOf(NodeJson::class, $foundNode);
    }

    public function testFindInstanceOfIncludesObjectKeys(): void
    {
        $jsonDocument = (new JsonParser())->parse(self::COMPOSER_JSON);

        $stringNodes = (new NodeJsonFinder())->findInstanceOf($jsonDocument, StringNode::class);

        $this->assertSame(
            ['name', 'acme/demo', 'autoload', 'psr-4', 'App\\', 'app/', 'keywords', 'json', 'ast', 'count'],
            array_map(static fn (StringNode $stringNode): string => $stringNode->value, $stringNodes),
        );
    }

    public function testFindInstanceOfRoot(): void
    {
        $jsonDocument = (new JsonParser())->parse(self::COMPOSER_JSON);

        $this->assertSame(
            [$jsonDocument],
            (new NodeJsonFinder())->findInstanceOf($jsonDocument, JsonDocument::class),
        );
    }

    public function testFindFirstInstanceOf(): void
    {
        $jsonDocument = (new JsonParser())->parse(self::COMPOSER_JSON);

        $numberNode = (new NodeJsonFinder())->findFirstInstanceOf($jsonDocument, NumberNode::class);

        $this->assertInstanceOf(NumberNode::class, $numberNode);
        $this->assertSame('2', $numberNode->rawValue);
    }

    public function testFindFirstInstanceOfReturnsNullWhenNothingMatches(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"keywords": ["json"]}');

        $booleanNode = (new NodeJsonFinder())->findFirstInstanceOf($jsonDocument, BooleanNode::class);

        $this->assertNotInstanceOf(BooleanNode::class, $booleanNode);
    }

    public function testFinderIsReusable(): void
    {
        $nodeJsonFinder = new NodeJsonFinder();
        $jsonDocument   = (new JsonParser())->parse('["a"]');
        $secondDocument = (new JsonParser())->parse('["b", "c"]');

        $this->assertCount(1, $nodeJsonFinder->findInstanceOf($jsonDocument, StringNode::class));
        $this->assertCount(2, $nodeJsonFinder->findInstanceOf($secondDocument, StringNode::class));
    }

    public function testFindOnSubtreeRoot(): void
    {
        $jsonDocument = (new JsonParser())->parse(self::COMPOSER_JSON);
        $arrayNode    = (new NodeJsonFinder())->findFirstInstanceOf($jsonDocument, ArrayNode::class);

        $this->assertInstanceOf(ArrayNode::class, $arrayNode);

        $stringNodes = (new NodeJsonFinder())->findInstanceOf($arrayNode, StringNode::class);

        $this->assertSame(
            ['json', 'ast'],
            array_map(static fn (StringNode $stringNode): string => $stringNode->value, $stringNodes),
        );
    }
}
