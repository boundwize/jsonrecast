<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Printer;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
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
            "{\n    \"name\": \"jsonrecast\"\n}",
            (new JsonPreservingPrinter())->print($objectNode),
        );
    }

    public function testItPreservesParsedArrayNode(): void
    {
        $jsonDocument = (new JsonParser())->parse('["json"]');

        $this->assertInstanceOf(ArrayNode::class, $jsonDocument->value);
        $this->assertSame('["json"]', (new JsonPreservingPrinter())->print($jsonDocument->value));
    }

    public function testItRejectsInvalidUtf8String(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to encode JSON string.');

        (new JsonPreservingPrinter())->print(new StringNode("\xB1"));
    }
}
