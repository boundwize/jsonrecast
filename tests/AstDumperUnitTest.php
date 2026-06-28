<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests;

use Boundwize\JsonRecast\AstDumper;
use Boundwize\JsonRecast\Node\AbstractNodeJson;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function stream_context_create;

final class AstDumperUnitTest extends TestCase
{
    public function testItDumpsEmptyContainersAndArrayItems(): void
    {
        $astDumper = new AstDumper();

        $this->assertSame(
            <<<'TXT'
ObjectNode
  items: []
TXT,
            $astDumper->dump(new ObjectNode([], afterOpenBrace: "\n", beforeCloseBrace: "\n")),
        );

        $this->assertSame(
            <<<'TXT'
ArrayNode
  items: []
TXT,
            $astDumper->dump(new ArrayNode([], afterOpenBracket: ' ', beforeCloseBracket: ' ')),
        );

        $this->assertSame(
            <<<'TXT'
ArrayNode
  items:
    [0]: ArrayItemNode
      value: StringNode(value: "jsonrecast")
TXT,
            $astDumper->dump(new ArrayNode(
                [
                    new ArrayItemNode(
                        new StringNode('jsonrecast'),
                        beforeValue: "\n    ",
                        afterValue: "\n",
                    ),
                ],
                afterOpenBracket: "\n",
                beforeCloseBracket: "\n",
            )),
        );
    }

    public function testItFormatsAttributeValueTypes(): void
    {
        $stringNode = new StringNode('jsonrecast');
        $stringNode->setAttribute('nullValue', null);
        $stringNode->setAttribute('arrayValue', ['path' => 'src/']);
        $stringNode->setAttribute('objectValue', new stdClass());
        $stringNode->setAttribute('resourceValue', stream_context_create());
        $stringNode->setAttribute('floatValue', 1.5);

        $dump = (new AstDumper(includeAttributes: true))->dump($stringNode);

        $this->assertStringContainsString('nullValue: null', $dump);
        $this->assertStringContainsString('arrayValue: {"path":"src/"}', $dump);
        $this->assertStringContainsString('objectValue: object(stdClass)', $dump);
        $this->assertStringContainsString('resourceValue: resource (stream-context)', $dump);
        $this->assertStringContainsString('floatValue: 1.5', $dump);
    }

    public function testItOmitsEmptyAttributes(): void
    {
        $this->assertSame(
            'StringNode(value: "jsonrecast")',
            (new AstDumper(includeAttributes: true))->dump(new StringNode('jsonrecast')),
        );
    }

    public function testItDumpsUnknownNodeJsonImplementations(): void
    {
        $dump = (new AstDumper())->dump(new class extends AbstractNodeJson {
        });

        $this->assertStringContainsString('AbstractNodeJson@anonymous', $dump);
    }

    public function testItThrowsWhenValueCannotBeEncoded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to encode AST dump value.');

        (new AstDumper())->dump(new StringNode("\xB1\x31"));
    }
}
