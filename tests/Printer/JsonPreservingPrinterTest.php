<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Printer;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeTraverser\NodeChangeSet;
use Boundwize\JsonRecast\Parser\JsonParser;
use Boundwize\JsonRecast\Printer\JsonPreservingPrinter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JsonPreservingPrinterTest extends TestCase
{
    public function testItPrintsNewScalarNodes(): void
    {
        $jsonPreservingPrinter = new JsonPreservingPrinter();

        $this->assertSame('1', $jsonPreservingPrinter->print(new NumberNode('1')));
        $this->assertSame('true', $jsonPreservingPrinter->print(new BooleanNode(true)));
        $this->assertSame('false', $jsonPreservingPrinter->print(new BooleanNode(false)));
        $this->assertSame('null', $jsonPreservingPrinter->print(new NullNode()));
    }

    public function testItPrintsNewEmptyCollections(): void
    {
        $jsonPreservingPrinter = new JsonPreservingPrinter();

        $this->assertSame('{}', $jsonPreservingPrinter->print(new ObjectNode([])));
        $this->assertSame('[]', $jsonPreservingPrinter->print(new ArrayNode([])));
    }

    public function testItPrettyPrintsContainerWithNewItem(): void
    {
        $objectNode = new ObjectNode([
            new ObjectItemNode(new StringNode('name'), new StringNode('jsonrecast')),
        ]);
        $objectNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '{}');

        $this->assertSame(
            <<<'JSON'
{
    "name": "jsonrecast"
}
JSON,
            (new JsonPreservingPrinter())->print($objectNode),
        );
    }

    public function testItPreservesParsedArrayNode(): void
    {
        $jsonDocument = (new JsonParser())->parse('["json"]');

        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);
        $this->assertSame('["json"]', (new JsonPreservingPrinter())->print($jsonDocument->value));
    }

    public function testItPreservesTrailingNewlineWhenDocumentAfterValueIsEmpty(): void
    {
        $jsonDocument = new JsonDocument(new StringNode('json'));
        $jsonDocument->setAttribute(NodeAttributes::NEWLINE, "\r\n");
        $jsonDocument->setAttribute(NodeAttributes::TRAILING_NEWLINE, true);

        $this->assertSame("\"json\"\r\n", (new JsonPreservingPrinter())->print($jsonDocument));
    }

    public function testItDoesNotReuseCommaWhitespaceWhenFirstInlineArrayItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('["first", "second"]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(0);

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('["second"]', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPreservesCommaWhitespaceWhenMiddleInlineArrayItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('["first", "second", "third"]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(1);

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('["first", "third"]', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPreservesCommaWhitespaceWhenLastInlineArrayItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('["first", "second", "third"]');
        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);

        $jsonDocument->value->removeAt(2);

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('["first", "second"]', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItDoesNotReuseCommaWhitespaceWhenFirstInlineObjectItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"first": 1, "second": 2}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('first');

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame('{"second": 2}', (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument));
    }

    public function testItPreservesCommaWhitespaceWhenMiddleInlineObjectItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"first": 1, "second": 2, "third": 3}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('second');

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame(
            '{"first": 1, "third": 3}',
            (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument),
        );
    }

    public function testItPreservesCommaWhitespaceWhenLastInlineObjectItemIsRemoved(): void
    {
        $jsonDocument = (new JsonParser())->parse('{"first": 1, "second": 2, "third": 3}');
        $this->assertInstanceOf(ObjectNode::class, $jsonDocument->value);

        $jsonDocument->value->remove('third');

        $nodeChangeSet = new NodeChangeSet();
        $nodeChangeSet->markChanged($jsonDocument->value);

        $this->assertSame(
            '{"first": 1, "second": 2}',
            (new JsonPreservingPrinter($nodeChangeSet))->print($jsonDocument),
        );
    }

    public function testItRejectsInvalidUtf8String(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to encode JSON string.');

        (new JsonPreservingPrinter())->print(new StringNode("\xB1"));
    }
}
