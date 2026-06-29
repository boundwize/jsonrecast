---
title: Quick Start
layout: default
nav_order: 2
---

# Quick Start
{: .no_toc }

Install JsonRecast, parse JSON into a document node, mutate it with a visitor, and print the result.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Installation

```bash
composer require boundwize/jsonrecast
```

JsonRecast requires PHP 8.2 or newer.

## Parse A Document

```php
<?php

use Boundwize\JsonRecast\JsonRecast;

$json = '{"name":"acme/demo","private":true}';

$document = JsonRecast::parse($json);
```

`JsonRecast::parse()` returns a `JsonDocument`. The document wraps the root JSON value, which may be an object, array, string, number, boolean, or null node.

## Inspect The AST

```php
echo JsonRecast::dumpAst($document);
```

```text
JsonDocument
  value: ObjectNode
    items:
      [0]: ObjectItemNode
        key: StringNode(value: "name")
        value: StringNode(value: "acme/demo")
      [1]: ObjectItemNode
        key: StringNode(value: "private")
        value: BooleanNode(value: true)
```

## Edit With A Visitor

Visitors can return a replacement node, return `NodeJsonVisitor::REMOVE_NODE`, or return the same mutated node to mark it as changed.

```php
<?php

use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;

$document = JsonRecast::parse('{"name":"acme/demo","private":true}');

$result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
    public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
    {
        if (
            $node instanceof ObjectItemNode
            && $path->isRoot()
            && $node->key->value === 'name'
        ) {
            $node->value = new StringNode('boundwize/jsonrecast');

            return $node;
        }

        return null;
    }
});

echo JsonRecast::print($result);
```

```json
{"name":"boundwize/jsonrecast","private":true}
```

## Add Values From PHP Data

Use `JsonValue::from()` when a new value starts as PHP data.

```php
<?php

use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\JsonRecast\Value\JsonValue;

$document = JsonRecast::parse('{"name":"boundwize/jsonrecast"}');

$result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
    public function leaveNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
    {
        if (! $node instanceof ObjectNode || ! $path->isRoot()) {
            return null;
        }

        $node->set('keywords', JsonValue::from(['json', 'ast', 'formatting']));

        return $node;
    }
});

echo JsonRecast::print($result);
```

```json
{
    "name": "boundwize/jsonrecast",
    "keywords": [
        "json",
        "ast",
        "formatting"
    ]
}
```
