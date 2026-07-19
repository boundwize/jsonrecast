---
title: Editing And Printing
layout: default
nav_order: 3
---

# Editing And Printing
{: .no_toc }

JsonRecast is designed for small, intentional edits to existing JSON files. Changed nodes are printed again; untouched nodes keep their original text.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Replace Object Values

Match an `ObjectItemNode` when you want both the key and the current value.

```php
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;

$document = JsonRecast::parse(<<<'JSON'
{
    "name" : "acme/demo",
    "type" : "library"
}
JSON);

$result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
    public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
    {
        if ($node instanceof ObjectItemNode && $path->isRoot() && $node->key->value === 'name') {
            $node->value = new StringNode('boundwize/jsonrecast');

            return $node;
        }

        return null;
    }
});

echo JsonRecast::print($result);
```

```json
{
    "name" : "boundwize/jsonrecast",
    "type" : "library"
}
```

The spaces around the colon remain because the object item was reprinted with its parsed trivia.

## Add Or Update Object Keys

`ObjectNode::set()` updates an existing key or appends a new item when the key is missing.

```php
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\JsonRecast\Value\JsonValue;

$result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
    public function leaveNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
    {
        if (! $node instanceof ObjectNode || ! $path->isRoot()) {
            return null;
        }

        $node->set('license', JsonValue::from('MIT'));

        return $node;
    }
});
```

Returning `$node` is important. It records the object in the change set so the preserving printer knows a new child was added.

## Remove Object Items

Return `NodeJsonVisitor::REMOVE_NODE` from an object item visitor to remove the whole key/value pair.

```php
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;

public function enterNode(NodeJson $node, NodeJsonPath $path): null|NodeJson|int
{
    if (
        $node instanceof ObjectItemNode
        && $path->isRoot()
        && $node->key->value === 'minimum-stability'
    ) {
        return NodeJsonVisitor::REMOVE_NODE;
    }

    return null;
}
```

Use `ObjectNode::remove('key')` when you are already editing a parent object and want to remove a key by name.

## Edit Arrays

`ArrayNode` exposes helpers for the common operations:

```php
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Value\JsonValue;

$array->append(JsonValue::from('added'));
$array->insert(1, JsonValue::from('middle'));
$array->removeAt(0);
```

You can also remove an array item by returning `NodeJsonVisitor::REMOVE_NODE` from an `ArrayItemNode` visitor.

```php
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;

public function enterNode(NodeJson $node, NodeJsonPath $path): null|NodeJson|int
{
    if (
        $node instanceof ArrayItemNode
        && $node->value instanceof StringNode
        && $node->value->value === 'temporary'
    ) {
        return NodeJsonVisitor::REMOVE_NODE;
    }

    return null;
}
```

Array paths are live and are reindexed after removals and insertions. This can
make an index-based removal predicate match repeatedly; see the
[live-index warning and safe removal patterns](../traversal-and-paths/#array-paths).

## Remove Empty Parents

Use `leaveNode()` when a parent decision depends on child edits that have already happened.

```php
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;

public function leaveNode(NodeJson $node, NodeJsonPath $path): ?int
{
    if (
        ! $node instanceof ObjectItemNode
        || ! $path->isRoot()
        || $node->key->value !== 'autoload-dev'
        || ! $node->value instanceof ObjectNode
    ) {
        return null;
    }

    $classmapItem = $node->value->get('classmap');

    if (
        $classmapItem instanceof ObjectItemNode
        && $classmapItem->value instanceof ArrayNode
        && $classmapItem->value->items === []
    ) {
        return NodeJsonVisitor::REMOVE_NODE;
    }

    return null;
}
```

## Preserved Output Example

Input:

```json
{
    "name": "acme/demo",
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "minimum-stability": "dev"
}
```

After changing `name`, adding `Boundwize\\JsonRecast\\`, and removing `minimum-stability`, the printer rewrites only the affected pieces:

```json
{
    "name": "boundwize/jsonrecast",
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Boundwize\\JsonRecast\\": "src/"
        }
    }
}
```
