---
title: AST Dumper
layout: default
nav_order: 7
---

# AST Dumper
{: .no_toc }

The AST dumper prints the node tree in a compact text form. It is useful when writing visitors, debugging paths, or explaining a transformation in a test failure.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Dump A Parsed Document

```php
use Boundwize\JsonRecast\JsonRecast;

$document = JsonRecast::parse(<<<'JSON'
{
    "name": "jsonrecast",
    "items": [
        1,
        true,
        null
    ]
}
JSON);

echo JsonRecast::dumpAst($document);
```

```text
JsonDocument
└── value: ObjectNode
    └── items (2 items)
        ├── [0]: ObjectItemNode
        │   ├── key: StringNode(value: "name")
        │   └── value: StringNode(value: "jsonrecast")
        └── [1]: ObjectItemNode
            ├── key: StringNode(value: "items")
            └── value: ArrayNode
                └── items (3 items)
                    ├── [0]: ArrayItemNode
                    │   └── value: NumberNode(rawValue: "1")
                    ├── [1]: ArrayItemNode
                    │   └── value: BooleanNode(value: true)
                    └── [2]: ArrayItemNode
                        └── value: NullNode
```

## Dump A Traversal Result

`JsonRecast::dumpAst()` also accepts `JsonRecastResult`.

```php
$result = JsonRecast::traverse($document, $visitor);

echo JsonRecast::dumpAst($result);
```

This dumps the transformed document from the result.

## Include Attributes

Pass `includeAttributes: true` when you need source offsets, original text, newline metadata, or other attributes.

```php
echo JsonRecast::dumpAst($document, includeAttributes: true);
```

Source-text attributes are printed in a readable form. Multiline source uses `|` when the text ends with a newline and `|-` when it does not. Single-line source text is printed as the original source fragment.

```php
$document = JsonRecast::parse(<<<'JSON'
{
    "name" : "jsonrecast"
}
JSON);

echo JsonRecast::dumpAst($document, includeAttributes: true);
```

```text
JsonDocument
├── attributes
│   ├── startOffset: 0
│   ├── endOffset: 30
│   ├── depth: 0
│   ├── indent: "    "
│   ├── originalText: |
│   │   {
│   │       "name" : "jsonrecast"
│   │   }
│   ├── source: |
│   │   {
│   │       "name" : "jsonrecast"
│   │   }
│   ├── newline: "\n"
│   └── trailingNewline: true
└── value: ObjectNode
    ├── attributes
    │   ├── startOffset: 0
    │   ├── endOffset: 29
    │   ├── depth: 0
    │   ├── indent: "    "
    │   └── originalText: |-
    │       {
    │           "name" : "jsonrecast"
    │       }
    └── items (1 item)
        └── [0]: ObjectItemNode
            ├── attributes
            │   ├── startOffset: 1
            │   ├── endOffset: 28
            │   ├── depth: 1
            │   ├── indent: "    "
            │   └── originalText: |
            │
            │           "name" : "jsonrecast"
            ├── key: StringNode(value: "name")
            │   └── attributes
            │       ├── startOffset: 6
            │       ├── endOffset: 12
            │       ├── depth: 1
            │       ├── indent: "    "
            │       └── originalText: "name"
            └── value: StringNode(value: "jsonrecast")
                └── attributes
                    ├── startOffset: 15
                    ├── endOffset: 27
                    ├── depth: 1
                    ├── indent: "    "
                    └── originalText: "jsonrecast"
```

## Maximum Depth

AST dumping limits JSON nesting depth to `512` by default, matching parsing and printing. This protects the recursive tree traversal from manually constructed or transformed trees that are deeper than the normal parser limit.

To change the limit, instantiate `AstDumper` directly:

```php
use Boundwize\JsonRecast\AstDumper;

$dumper = new AstDumper(
    includeAttributes: true,
    maximumDepth: 1024,
);

echo $dumper->dump($document);
```

The limit uses JSON nesting semantics: `JsonDocument`, `ObjectItemNode`, and `ArrayItemNode` are wrapper nodes and do not add depth. Each nested object or array level does.

The configured limit also applies when nested array attributes are encoded. An AST that exceeds the limit throws `InvalidArgumentException` with the message `Maximum stack depth exceeded.` An attribute value that cannot be encoded within the limit throws `RuntimeException` with the message `Unable to encode AST dump value.`

The maximum depth must be greater than `0`.

Attribute dumps are verbose because they include exact source text. They are best suited to tests and debugging sessions.
