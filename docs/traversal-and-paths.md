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

- `null`: keep the current node instance and do not record an explicit change. Any in-place mutations remain in the AST.
- `NodeJson`: replace the current node or mark the same mutated node as changed.
- `NodeJsonVisitor::REMOVE_NODE`: remove the current object item or array item.
- `NodeJsonVisitor::STOP_TRAVERSAL`: stop the traversal, keeping the tree as it is.

The root node, document value, object keys, and scalar values cannot be removed directly. Remove their containing `ObjectItemNode` or `ArrayItemNode` instead.

## Stopping Traversal

Return `NodeJsonVisitor::STOP_TRAVERSAL` from `enterNode()` or `leaveNode()` when the traversal has done its job — for example, once a target node has been found or edited:

```php
public function enterNode(NodeJson $node, NodeJsonPath $path): null|NodeJson|int
{
    if ($node instanceof ObjectNode && $path->matches(['autoload', 'psr-4'])) {
        $node->set('Boundwize\\JsonRecast\\', new StringNode('src/'));

        return $node;
    }

    return null;
}

public function leaveNode(NodeJson $node, NodeJsonPath $path): null|NodeJson|int
{
    if ($node instanceof ObjectNode && $path->matches(['autoload', 'psr-4'])) {
        return NodeJsonVisitor::STOP_TRAVERSAL;
    }

    return null;
}
```

After a visitor returns `STOP_TRAVERSAL`:

- No further nodes are entered or left, and the remaining visitors are skipped for the current node.
- The current root is kept unchanged, and no change is recorded for the stop itself.
- `afterTraverse()` still runs on every visitor so state can be cleaned up.

`STOP_TRAVERSAL` is only valid from `enterNode()` and `leaveNode()`; returning it from `beforeTraverse()` or `afterTraverse()` throws a `LogicException`. This is the mechanism behind `NodeJsonFinder::findFirst()` — see [Finding Nodes](node-finder.html).

## Replacement Nodes Are Traversed

Replacement nodes are traversed again, so your visitors see the values inside a replacement. This is intentional: it lets later rules process what an earlier rule produced.

It also means a visitor whose replacement contains its own trigger replaces forever — each pass installs a fresh trigger inside the previous replacement:

```php
public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
{
    if ($node instanceof NumberNode && $node->rawValue === '1') {
        return JsonValue::from(['m' => [1, 2]]); // contains another 1
    }

    return null;
}
```

{: .warning }
> A self-triggering replacement nests the tree deeper on every pass until PHP
> exhausts its memory limit. The resulting fatal error surfaces during path
> allocation, far from the visitor that caused it. Making the replacement
> impossible to re-match is your responsibility as the visitor author.

Guard the replacement with a fire-once flag:

```php
if (! $this->replaced && $node instanceof NumberNode && $node->rawValue === '1') {
    $this->replaced = true;

    return JsonValue::from(['m' => [1, 2]]);
}
```

A `NodeJsonPath` verification also works as a guard. The values inside a replacement live at deeper paths than the value they replaced, so bounding the depth can never re-trigger:

```php
if ($node instanceof NumberNode && $node->rawValue === '1' && $path->depth() === 1) {
    return JsonValue::from(['m' => [1, 2]]);
}
```

The original `1` is visited at depth `1` (path `[0]`). The `1` inside the replacement is visited at depth `3` (path `[0, 'm', 0]`), so the visitor fires once. An exact match such as `$path->matches([0])` works the same way when the target location is known in advance.

## Input Tree Validation

`traverse()` validates the input tree at entry, before any visitor runs, using the same guard as the printers. A cyclic tree — a container placed somewhere below itself, for example via `set()` or `append()` — throws a catchable `RuntimeException` with the message `Cyclic JSON AST detected.` A tree whose container nesting exceeds the configured maximum depth throws `InvalidArgumentException` with the message `Maximum stack depth exceeded.` Without this guard, traversing a cyclic tree would recurse until PHP's memory limit kills the process with an uncatchable fatal error.

The guard also rejects wrapper nodes placed where a JSON value belongs. A `JsonDocument`, `ObjectItemNode`, or `ArrayItemNode` used as the value of a document or of another item — for example `new ObjectItemNode($key, new ObjectItemNode(...))` — throws a `RuntimeException` such as `ObjectItemNode cannot be used as a JSON value.` Printers would otherwise render the inner item as a bare `"key": value` fragment without surrounding braces, producing invalid JSON.

The default limit is `512`. Raise or lower it through the facade or the traverser constructor — see [Maximum Depth](parsing-and-printers.html#maximum-depth):

```php
$result = JsonRecast::traverse($document, $visitor, maximumDepth: 1024);

$traverser = new NodeJsonTraverser(maximumDepth: 1024);
```

The guard tracks only the active path, so the same node appearing under two sibling positions is not a cycle; such aliased subtrees traverse normally. `NodeJsonFinder` runs a traverser internally, so `find()` and `findFirst()` reject cyclic trees the same way.

The guard runs once, at entry. Nodes a visitor introduces mid-traversal are not re-validated — a self-triggering replacement that deepens the tree forever remains your responsibility as the visitor author, as described in the warning above.

## Change Tracking

JsonRecast keeps explicit change metadata outside the AST. The traverser records a node in `NodeChangeSet` when a visitor returns it.

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

Returning the mutated object makes the transformation observable through the result:

```php
$result->changeSet->isChanged($result->document->value); // true
```

Returning `null` does not undo an in-place mutation. It keeps the same node in the AST but does not record that node in `NodeChangeSet`. The preserving printer independently detects changed scalar values, stale container text, and changed descendants, so the added `license` still appears when `$result` is printed.

Return the node when callers need an explicit change signal, such as for dry-run or "no changes needed" reporting. Return `null` when no explicit record is needed. After traversal, pass the `JsonRecastResult` to `JsonRecast::print()` so the printer receives any explicit change records.

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
