<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Printer;

use Boundwize\JsonRecast\Attribute\NodeAttributes;
use Boundwize\JsonRecast\Node\AbstractNodeJson;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\Printer\JsonPrettyPrinter;
use Boundwize\JsonRecast\Value\JsonValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;

final class JsonPrettyPrinterTest extends TestCase
{
    public function testItPrintsValidJsonWhenCollectionItemKeysAreNonSequential(): void
    {
        $nodeJson = JsonValue::from([1, 2, 3]);
        $this->assertInstanceOf(ArrayNode::class, $nodeJson);
        $items = $nodeJson->items;
        unset($items[0]);
        // Reproduce a runtime contract violation without making this test fail static analysis.
        (new ReflectionProperty($nodeJson, 'items'))->setValue($nodeJson, $items);

        $this->assertSame(
            "[\n    2,\n    3\n]",
            (new JsonPrettyPrinter())->print($nodeJson),
        );
    }

    public function testItPrintsScalarNodes(): void
    {
        $jsonPrettyPrinter = new JsonPrettyPrinter();

        $this->assertSame('1', $jsonPrettyPrinter->print(new NumberNode('1')));
        $this->assertSame('true', $jsonPrettyPrinter->print(new BooleanNode(true)));
        $this->assertSame('false', $jsonPrettyPrinter->print(new BooleanNode(false)));
        $this->assertSame('null', $jsonPrettyPrinter->print(new NullNode()));
    }

    public function testItPrintsEmptyCollections(): void
    {
        $jsonPrettyPrinter = new JsonPrettyPrinter();

        $this->assertSame('{}', $jsonPrettyPrinter->print(new ObjectNode([])));
        $this->assertSame('[]', $jsonPrettyPrinter->print(new ArrayNode([])));
    }

    public function testItPrintsStringNodeWithoutEscapingUnicode(): void
    {
        $value = "Gr\xC3\xBC\xC3\x9Fe";

        $this->assertSame('"' . $value . '"', (new JsonPrettyPrinter())->print(new StringNode($value)));
    }

    public function testItRejectsObjectItemNodeAsPrintedRoot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ObjectItemNode cannot be printed as a JSON document.');

        (new JsonPrettyPrinter())->print(
            new ObjectItemNode(new StringNode('inner'), new NumberNode('1')),
        );
    }

    public function testItRejectsObjectItemNodeUsedAsValue(): void
    {
        $objectNode = new ObjectNode([
            new ObjectItemNode(
                new StringNode('outer'),
                new ObjectItemNode(new StringNode('inner'), new NumberNode('1')),
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ObjectItemNode cannot be used as a JSON value.');

        (new JsonPrettyPrinter())->print($objectNode);
    }

    public function testItRejectsInvalidUtf8String(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to encode JSON string.');

        (new JsonPrettyPrinter())->print(new StringNode("\xB1"));
    }

    public function testItRejectsInvalidNumberLexeme(): void
    {
        $numberNode           = new NumberNode('1');
        $numberNode->rawValue = '01';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to encode JSON number.');

        (new JsonPrettyPrinter())->print($numberNode);
    }

    public function testItPrintsValidNumberLexemesVerbatim(): void
    {
        foreach (['0', '-0', '1e0', '1.00', '-0.5E+10', '123', '9e-2'] as $rawValue) {
            $this->assertSame($rawValue, (new JsonPrettyPrinter())->print(new NumberNode($rawValue)));
        }
    }

    public function testItRejectsNodeThatExceedsMaximumNestingDepth(): void
    {
        $nodeJson = JsonValue::from([[[0]]], maximumDepth: 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        (new JsonPrettyPrinter(maximumDepth: 3))->print($nodeJson);
    }

    public function testItRejectsCyclicNodeTree(): void
    {
        // a cyclic document would recurse at the same nesting depth forever,
        // so it must be rejected before printing starts
        $jsonDocument        = new JsonDocument(new NullNode());
        $jsonDocument->value = $jsonDocument;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cyclic JSON AST detected.');

        (new JsonPrettyPrinter())->print($jsonDocument);
    }

    public function testItPrintsContainerWithScalarWithinMaximumNestingDepth(): void
    {
        $arrayNode = new ArrayNode([
            new ArrayItemNode(new NumberNode('1')),
        ]);

        $this->assertSame(
            "[\n    1\n]",
            (new JsonPrettyPrinter(maximumDepth: 2))->print($arrayNode),
        );

        $objectNode = new ObjectNode([
            new ObjectItemNode(
                new StringNode('value'),
                new NumberNode('1'),
            ),
        ]);

        $this->assertSame(
            "{\n    \"value\": 1\n}",
            (new JsonPrettyPrinter(maximumDepth: 2))->print($objectNode),
        );
    }

    public function testMaximumNestingDepthCanBeOverridden(): void
    {
        $nodeJson = JsonValue::from([[0]], maximumDepth: 3);

        $this->assertSame("[\n    [\n        0\n    ]\n]", (new JsonPrettyPrinter(maximumDepth: 3))->print($nodeJson));
    }

    public function testMaximumNestingDepthMustBeGreaterThanZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum depth must be greater than 0.');

        new JsonPrettyPrinter(maximumDepth: 0);
    }

    public function testItPrintsWithWhitespaceOnlyCustomIndent(): void
    {
        $nodeJson = JsonValue::from(['name' => 'jsonrecast']);

        $this->assertSame(
            "{\n\t\"name\": \"jsonrecast\"\n}",
            (new JsonPrettyPrinter(indent: "\t"))->print($nodeJson),
        );
    }

    public function testItRejectsNonWhitespaceIndent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Indent must contain only spaces or tabs.');

        new JsonPrettyPrinter(indent: 'x');
    }

    public function testItRejectsCollectionAtMaximumNestingDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        (new JsonPrettyPrinter(maximumDepth: 1))->print(new ArrayNode([]));
    }

    public function testItPrintsEmptyCollectionWithinMaximumNestingDepth(): void
    {
        $this->assertSame('[]', (new JsonPrettyPrinter(maximumDepth: 2))->print(new ArrayNode([])));
        $this->assertSame('{}', (new JsonPrettyPrinter(maximumDepth: 2))->print(new ObjectNode([])));
        $this->assertSame(
            "[\n    []\n]",
            (new JsonPrettyPrinter(maximumDepth: 3))->print(JsonValue::from([[]])),
        );
        $this->assertSame(
            "{\n    \"value\": {}\n}",
            (new JsonPrettyPrinter(maximumDepth: 3))->print(JsonValue::from(['value' => new stdClass()])),
        );
    }

    /**
     * NodeJson is a public interface, so a node outside the built-in set reaches
     * this printer too. It exposes no structure to lay out, leaving its recorded
     * text the only thing to emit — the same option the preserving printer has,
     * so both accept and refuse the very same trees.
     */
    public function testItPrintsUnknownNodeJsonImplementationFromItsOriginalText(): void
    {
        $unknownNode = new class extends AbstractNodeJson {
        };
        $unknownNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '"jsonrecast"');

        $this->assertSame(
            <<<'JSON'
"jsonrecast"
JSON,
            (new JsonPrettyPrinter())->print(new JsonDocument($unknownNode)),
        );
    }

    public function testItPrintsNestedUnknownNodeJsonImplementationWithItsOwnSpacingIntact(): void
    {
        $unknownNode = new class extends AbstractNodeJson {
        };
        $unknownNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '{"k1":   7  }');

        // The printer cannot see inside an unknown node, so the interior spacing
        // it records is spacing there is no way to canonicalise.
        $this->assertSame(
            <<<'JSON'
{
    "x": {"k1":   7  }
}
JSON,
            (new JsonPrettyPrinter())->print(
                new ObjectNode([new ObjectItemNode(new StringNode('x'), $unknownNode)]),
            ),
        );
    }

    public function testItRemovesSurroundingWhitespaceFromUnknownNodeOriginalText(): void
    {
        $unknownNode = new class extends AbstractNodeJson {
        };
        $unknownNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, " \t42\r\n");

        $this->assertSame('42', (new JsonPrettyPrinter())->print($unknownNode));
    }

    public function testItDoesNotLeakUnknownNodeSurroundingWhitespaceIntoPrettyOutput(): void
    {
        $unknownNode = new class extends AbstractNodeJson {
        };
        $unknownNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, ' 42 ');

        $objectNode = new ObjectNode([
            new ObjectItemNode(new StringNode('value'), $unknownNode),
        ]);

        $this->assertSame(
            <<<'JSON'
{
    "value": 42
}
JSON,
            (new JsonPrettyPrinter())->print($objectNode),
        );
    }

    /**
     * The ragged result below is intended, not an oversight. Reindenting the
     * recorded text would mean deciding which of its lines are structure and
     * which are string content — exactly the reading an unknown node denies the
     * printer. Refusing the tree instead is the only other option, and that is
     * the asymmetry with the preserving printer this handling exists to remove.
     */
    public function testItPrintsNestedUnknownNodeJsonImplementationAtItsOwnIndentation(): void
    {
        $unknownNode = new class extends AbstractNodeJson {
        };
        $unknownNode->setAttribute(
            NodeAttributes::ORIGINAL_TEXT,
            <<<'JSON'
{
  "q": 1
}
JSON,
        );

        $objectNode = new ObjectNode([
            new ObjectItemNode(new StringNode('a'), new NumberNode('1')),
            new ObjectItemNode(new StringNode('zz'), $unknownNode),
        ]);

        $this->assertSame(
            <<<'JSON'
{
    "a": 1,
    "zz": {
  "q": 1
}
}
JSON,
            (new JsonPrettyPrinter())->print($objectNode),
        );
    }

    public function testItRejectsUnknownNodeJsonImplementationWithoutOriginalText(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported JSON node.');

        (new JsonPrettyPrinter())->print(new JsonDocument(new class extends AbstractNodeJson {
        }));
    }

    public function testItRejectsUnknownNodeJsonImplementationWhoseOriginalTextIsNoJsonValue(): void
    {
        $unknownNode = new class extends AbstractNodeJson {
        };
        $unknownNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '"foo": 123');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported JSON node.');

        (new JsonPrettyPrinter())->print(new JsonDocument($unknownNode));
    }

    /**
     * @return iterable<string, array{int, ?string}>
     */
    public static function nestedUnknownNodeDepthProvider(): iterable
    {
        yield 'text outgrows the remaining depth' => [3, null];
        yield 'text fits the remaining depth' => [
            4,
            <<<'JSON'
{
    "zz": {
        "x": []
    }
}
JSON,
        ];
    }

    /**
     * The tree guard cannot see inside an unknown node, so its recorded text is
     * the one way a printable tree can outgrow the maximum depth — bounded here
     * at the same position the preserving printer bounds it.
     */
    #[DataProvider('nestedUnknownNodeDepthProvider')]
    public function testItBoundsNestedUnknownNodeTextByRemainingDepth(int $maximumDepth, ?string $expected): void
    {
        $unknownNode = new class extends AbstractNodeJson {
        };
        $unknownNode->setAttribute(NodeAttributes::ORIGINAL_TEXT, '[]');

        $objectNode = new ObjectNode([
            new ObjectItemNode(
                new StringNode('zz'),
                new ObjectNode([new ObjectItemNode(new StringNode('x'), $unknownNode)]),
            ),
        ]);

        if ($expected === null) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unsupported JSON node.');
        }

        $this->assertSame($expected, (new JsonPrettyPrinter(maximumDepth: $maximumDepth))->print($objectNode));
    }
}
