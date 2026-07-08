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
└── value: ObjectNode (2 items)
    ├── [0]: ObjectItemNode
    │   ├── key: StringNode(value: "name")
    │   └── value: StringNode(value: "jsonrecast")
    └── [1]: ObjectItemNode
        ├── key: StringNode(value: "items")
        └── value: ArrayNode (3 items)
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

You can instantiate the utility directly too:

```php
use Boundwize\JsonRecast\AstDumper;

$dumper = new AstDumper(includeAttributes: true);

echo $dumper->dump($document);
```

Attribute dumps are verbose because they include exact source text. They are best suited to tests and debugging sessions.
