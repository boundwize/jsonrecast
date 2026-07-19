---
title: Traversal And Paths
layout: default
nav_order: 4
---

# Traversal And Paths
{: .no_toc }

Visitors are the main extension point. They receive nodes in depth-first order plus a `NodeJsonPath` that describes where the current JSON value lives.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Visitor Hooks

Implement `NodeJsonVisitor` directly or extend `NodeJsonVisitorAbstract` and override only the hooks you need.

```php
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;

final class MyVisitor extends NodeJsonVisitorAbstract
{
    public function beforeTraverse(NodeJson $node): ?NodeJson
    {
        return null;
    }

    public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
    {
        return null;
    }

    public function leaveNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
    {
        return null;
    }

    public function afterTraverse(NodeJson $node): ?NodeJson
    {
        return null;
    }
}
```

Hook return values mean:

- `null`: leave the current node unchanged.
- `NodeJson`: replace the current node or mark the same mutated node as changed.
- `NodeJsonVisitor::REMOVE_NODE`: remove the current object item or array item.

The root node, document value, object keys, and scalar values cannot be removed directly. Remove their containing `ObjectItemNode` or `ArrayItemNode` instead.

## Change Tracking

JsonRecast keeps change metadata outside the AST. The traverser records a change when a visitor returns a node.

```php
public function leaveNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
{
    if (! $node instanceof ObjectNode || ! $path->isRoot()) {
        return null;
    }

    $node->set('license', JsonValue::from('MIT'));

    return $node;
}
```

If you mutate a node and return `null`, the object changes in memory, but the preserving printer does not know that the container needs to be rebuilt.

## Path Basics

`NodeJsonPath` is immutable. Each segment is either an object key or an array index.

```php
$path->isRoot();
$path->depth();
$path->segments();
$path->last();
```

For object values:

```php
$path->isObjectValue('name');
$path->matchesObjectKeys(['autoload', 'psr-4']);
```

For arrays and mixed object/array nesting:

```php
$path->isArrayValue(0);
$path->matches(['items', 0, 'name']);
```

## Object Item Paths

Object item nodes receive the path of their parent object. Their value receives the path including the key.

For this document:

```json
{"autoload":{"psr-4":{"App\\":"src/"}}}
```

The `ObjectItemNode` for `autoload` receives the root path. Its value, the nested object, receives `['autoload']`. The `psr-4` object receives `['autoload', 'psr-4']`.

## Array Paths

Array values receive their current numeric index:

```php
public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
{
    if ($node instanceof StringNode && $path->matches(['packages', 0, 'name'])) {
        return new StringNode('boundwize/jsonrecast');
    }

    return null;
}
```

Array indexes are live: after a removal or insertion, traversal continues using the
updated indexes.

{: .warning }
> Do not use a live array index as the removal predicate. For example, returning
> `NodeJsonVisitor::REMOVE_NODE` whenever `$path->isArrayValue(0)` visits
> `[10,20,30]` as index `0` three times and produces `[]`, not `[20,30]`. Each
> removal shifts the next item to index `0`, where it matches again. Match the
> item by value instead, or collect the original indexes first and remove them
> from the parent array in descending order.

To remove the value `10`, make the value—not its changing position—the predicate:

```php
if (
    $node instanceof ArrayItemNode
    && $node->value instanceof NumberNode
    && $node->value->rawValue === '10'
) {
    return NodeJsonVisitor::REMOVE_NODE;
}
```

For positional edits, collect against the unchanged parent and then remove in
descending order so one removal cannot change a later target:

```php
$indexesToRemove = [];

foreach ($array->items as $index => $item) {
    if ($index === 0) {
        $indexesToRemove[] = $index;
    }
}

foreach (array_reverse($indexesToRemove) as $index) {
    $array->removeAt($index);
}
```

## Multiple Visitors

Use `NodeJsonTraverser` directly when you want several visitors in one pass.

```php
use Boundwize\JsonRecast\NodeTraverser\NodeJsonTraverser;

$traverser = new NodeJsonTraverser();
$traverser->addVisitor(new FirstVisitor());
$traverser->addVisitor(new SecondVisitor());

$traversalResult = $traverser->traverse($document);
```

`JsonRecast::traverse()` is a convenience method for the common case of one visitor and a `JsonDocument` result.
