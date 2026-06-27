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
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function testItRejectsInvalidUtf8String(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to encode JSON string.');

        (new JsonPrettyPrinter())->print(new StringNode("\xB1"));
    }
}
