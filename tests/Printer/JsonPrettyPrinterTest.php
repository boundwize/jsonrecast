<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests\Printer;

use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\BooleanNode;
use Boundwize\JsonRecast\Node\NullNode;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\Printer\JsonPrettyPrinter;
use Boundwize\JsonRecast\Value\JsonValue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class JsonPrettyPrinterTest extends TestCase
{
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

    public function testItRejectsInvalidUtf8String(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to encode JSON string.');

        (new JsonPrettyPrinter())->print(new StringNode("\xB1"));
    }

    public function testItRejectsNodeThatExceedsMaximumNestingDepth(): void
    {
        $nodeJson = JsonValue::from([[0]], maximumDepth: 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        (new JsonPrettyPrinter(maximumDepth: 2))->print($nodeJson);
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

    public function testItPrintsEmptyCollectionAtMaximumNestingDepth(): void
    {
        // printing mirrors json_encode(), which lets an empty container occupy the
        // final depth level (json_encode([[]], depth: 2) succeeds), while parsing
        // mirrors json_decode(), which rejects it (json_decode('[[]]', depth: 2))
        $this->assertSame('[]', (new JsonPrettyPrinter(maximumDepth: 1))->print(new ArrayNode([])));
        $this->assertSame('{}', (new JsonPrettyPrinter(maximumDepth: 1))->print(new ObjectNode([])));
        $this->assertSame(
            "[\n    []\n]",
            (new JsonPrettyPrinter(maximumDepth: 2))->print(JsonValue::from([[]])),
        );
        $this->assertSame(
            "{\n    \"value\": {}\n}",
            (new JsonPrettyPrinter(maximumDepth: 2))->print(JsonValue::from(['value' => new stdClass()])),
        );
    }
}
