<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Tests;

use Boundwize\JsonRecast\AstDumper;
use Boundwize\JsonRecast\Node\AbstractNodeJson;
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use InvalidArgumentException;
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
└── items (0 items)
TXT,
            $astDumper->dump(new ObjectNode([], afterOpenBrace: "\n", beforeCloseBrace: "\n")),
        );

        $this->assertSame(
            <<<'TXT'
ArrayNode
└── items (0 items)
TXT,
            $astDumper->dump(new ArrayNode([], afterOpenBracket: ' ', beforeCloseBracket: ' ')),
        );

        $this->assertSame(
            <<<'TXT'
ArrayNode
└── items (1 item)
    └── [0]: ArrayItemNode
        └── value: StringNode(value: "jsonrecast")
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

    public function testItFormatsUnicodeValuesWithoutEscapingThem(): void
    {
        $city       = "M\xC3\xBCnchen";
        $note       = "Gr\xC3\xBC\xC3\x9Fe";
        $stringNode = new StringNode($note);
        $stringNode->setAttribute('city', $city);
        $stringNode->setAttribute('localized', ['note' => $note]);

        $dump = (new AstDumper(includeAttributes: true))->dump($stringNode);

        $this->assertStringContainsString('StringNode(value: "' . $note . '")', $dump);
        $this->assertStringContainsString('city: "' . $city . '"', $dump);
        $this->assertStringContainsString('localized: {"note":"' . $note . '"}', $dump);
    }

    public function testItFormatsSourceTextAttributesWithoutEscapingQuotes(): void
    {
        $stringNode = new StringNode('name');
        $stringNode->setAttribute('originalText', '"name"');
        $stringNode->setAttribute('label', '"name"');

        $this->assertSame(
            <<<'TXT'
StringNode(value: "name")
└── attributes
    ├── originalText: "name"
    └── label: "\"name\""
TXT,
            (new AstDumper(includeAttributes: true))->dump($stringNode),
        );
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

    public function testItReducesMaximumDepthForAttributeEncodingInsideNestedStack(): void
    {
        $nestedNode = new ArrayNode([
            new ArrayItemNode(new StringNode('value')),
        ]);
        $nestedNode->setAttribute('metadata', [1 => [2], 2]);

        $arrayNode = new ArrayNode([
            new ArrayItemNode($nestedNode),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to encode AST dump value.');

        (new AstDumper(includeAttributes: true, maximumDepth: 2))->dump($arrayNode);
    }

    public function testAttributeEncodingDepthResetsForNextInlineValue(): void
    {
        $nestedNode = new ArrayNode([
            new ArrayItemNode(new StringNode('value')),
        ]);
        $nestedNode->setAttribute('metadata', [1 => [2], 2]);

        $arrayNode = new ArrayNode([
            new ArrayItemNode($nestedNode),
        ]);

        $this->assertStringContainsString(
            'metadata: {"1":[2],"2":2}',
            (new AstDumper(includeAttributes: true, maximumDepth: 3))->dump($arrayNode),
        );
    }

    public function testItRejectsNodeThatExceedsMaximumNestingDepth(): void
    {
        // mirrors json_encode([[["value"]]], depth: 2), which fails, while
        // json_encode([["value"]], depth: 2) succeeds
        $arrayNode = new ArrayNode([
            new ArrayItemNode(new ArrayNode([
                new ArrayItemNode(new ArrayNode([
                    new ArrayItemNode(new StringNode('value')),
                ])),
            ])),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum stack depth exceeded.');

        (new AstDumper(maximumDepth: 2))->dump($arrayNode);
    }

    public function testItDumpsScalarAtMaximumNestingDepth(): void
    {
        // mirrors json_encode(["value"], depth: 1): only entering another
        // container consumes a nesting level, scalar leaves do not exceed
        // the depth
        $arrayNode = new ArrayNode([
            new ArrayItemNode(new StringNode('value')),
        ]);

        $this->assertSame(
            <<<'TXT'
ArrayNode
└── items (1 item)
    └── [0]: ArrayItemNode
        └── value: StringNode(value: "value")
TXT,
            (new AstDumper(maximumDepth: 1))->dump($arrayNode),
        );
    }

    public function testMaximumNestingDepthCanBeOverridden(): void
    {
        $arrayNode = new ArrayNode([
            new ArrayItemNode(new ArrayNode([
                new ArrayItemNode(new StringNode('value')),
            ])),
        ]);

        $this->assertStringContainsString(
            'value: StringNode(value: "value")',
            (new AstDumper(maximumDepth: 3))->dump($arrayNode),
        );
    }

    public function testMaximumNestingDepthMustBeGreaterThanZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum depth must be greater than 0.');

        new AstDumper(maximumDepth: 0);
    }
}
