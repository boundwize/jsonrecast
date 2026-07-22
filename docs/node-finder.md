---
title: Finding Nodes
layout: default
nav_order: 5
---

# Finding Nodes
{: .no_toc }

`NodeJsonFinder` locates nodes without writing a visitor. It mirrors PHP-Parser's `NodeFinder` and runs a normal `NodeJsonTraverser` under the hood, so it visits exactly the nodes a visitor would see.

Reach for it when a tool needs to *read* a JSON file before deciding what to do: look up a value, audit a config, or check whether an edit is needed at all.

## Contents
{: .no_toc }

1. TOC
{:toc}

## The Document Used Below

Every example on this page works against this `composer.json`:

```php
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\NodeJsonFinder;

$document = JsonRecast::parse(<<<'JSON'
{
    "name": "acme/demo",
    "require": {
        "php": "^8.2",
        "monolog/monolog": "^3.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.5"
    },
    "repositories": [
        {"type": "composer", "url": "http://repo.internal.example/"},
        {"type": "vcs", "url": "https://github.com/acme/lib"}
    ],
    "scripts": {
        "test": "phpunit"
    }
}
JSON);

$finder = new NodeJsonFinder();
```

## Reading a Nested Value

Which version of Monolog does this project require? `findFirst()` walks the tree depth-first and returns the first node the filter accepts, or `null`. The filter receives the node plus its `NodeJsonPath`, so no manual descending through `require` is needed:

```php
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;

$constraint = $finder->findFirst(
    $document,
    static fn (NodeJson $node, NodeJsonPath $path): bool =>
        $node instanceof StringNode
        && $path->matches(['require', 'monolog/monolog']),
);

if ($constraint instanceof StringNode) {
    echo $constraint->value; // ^3.0
}
```

`findFirst()` stops the traversal as soon as the filter matches, using `NodeJsonVisitor::STOP_TRAVERSAL`, so nothing after the match is visited.

Without the finder, the same lookup is a chain of `get()` calls with an `instanceof` check at every step, or a one-off visitor class. The finder replaces both with one callback.

## Auditing a Whole File

Flag every insecure `http://` URL, wherever it appears — top level, inside `repositories`, or anywhere a future schema puts one. From the `$document` object, you can resolve the matching nodes, eg:

```php
$insecureNodes = $finder->find(
    $document,
    static fn (NodeJson $node, NodeJsonPath $path): bool =>
        $node instanceof StringNode
        && str_starts_with($node->value, 'http://'),
);

// $insecureNodes:
// [
//     StringNode { value: 'http://repo.internal.example/' },
// ]
```

You can also find with extracted nodes data: found nodes do not record their own paths, so when the report should also say *where* each problem is, extract it in the filter as it arrives:

```php
$insecure = [];

$finder->find(
    $document,
    static function (NodeJson $node, NodeJsonPath $path) use (&$insecure): bool {
        if ($node instanceof StringNode && str_starts_with($node->value, 'http://')) {
            $insecure[] = [
                'value'     => $node->value,
                'path_info' => array_map(
                    static fn ($segment) => $segment->value, $path->segments()
                ),
            ];

            return true;
        }

        return false;
    },
);

// $insecure:
// [
//     [
//         'value'     => 'http://repo.internal.example/',
//         'path_info' => ['repositories', 0, 'url'],
//     ],
// ]
```

This is the shape of most lint-style checks: no fixed paths, one predicate. Prefer the returned node list when the nodes alone are enough; reach for the collecting filter only when the location matters too.

## Skipping Work When Nothing Changes

Automation that rewrites files on every run produces noisy commits. Use `findFirst()` as a dry-run check and only touch the file when something actually needs to change:

```php
$outdatedPhp = $finder->findFirst(
    $document,
    static fn (NodeJson $node, NodeJsonPath $path): bool =>
        $node instanceof StringNode
        && $path->matches(['require', 'php'])
        && $node->value !== '^8.3',
);

if ($outdatedPhp === null) {
    return; // nothing to do — the file is not rewritten
}
```

When the check does hit, hand the edit to a visitor (see [Finder Reads, Visitors Write](#finder-reads-visitors-write) below for why):

```php
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;

$result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
    public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
    {
        if ($node instanceof StringNode && $path->matches(['require', 'php'])) {
            return new StringNode('^8.3');
        }

        return null;
    }
});

file_put_contents('composer.json', JsonRecast::print($result));
```

Only the `"php"` line changes in the output; the rest of the file keeps its original formatting.

## Scoping a Search to a Subtree

Any node works as the starting point, so a search can be limited to one section. List the script commands without matching strings elsewhere in the file:

```php
use Boundwize\JsonRecast\Node\ObjectNode;

$scripts = $finder->findFirst(
    $document,
    static fn (NodeJson $node, NodeJsonPath $path): bool =>
        $node instanceof ObjectNode
        && $path->matches(['scripts']),
);

$commands = $finder->find(
    $scripts,
    static fn (NodeJson $node, NodeJsonPath $path): bool =>
        $node instanceof StringNode
        && ! $path->isRoot(),
);

// $commands:
// [
//     StringNode { value: 'phpunit' },
// ]
```

Paths passed to the filter are relative to the node the search starts from — inside `$scripts`, the `"phpunit"` value lives at `['test']`, not `['scripts', 'test']`.

## Instance Lookup Visits Keys Too

`findInstanceOf()` and `findFirstInstanceOf()` filter by node class:

```php
$stringNodes = $finder->findInstanceOf($document, StringNode::class);
```

Because finder traversal matches normal visitor traversal, this also finds object-key `StringNode` instances — for the document above, `"name"`, `"require"`, and `"php"` are returned alongside `"acme/demo"` and `"^8.2"`. That is intentional: the finder never filters nodes a visitor would see.

The `! $path->isRoot()` trick from the previous example does not exclude keys in general (keys share their item's path). When only JSON string *values* matter, make the value position part of the predicate:

```php
use Boundwize\JsonRecast\Node\ArrayItemNode;
use Boundwize\JsonRecast\Node\JsonDocument;
use Boundwize\JsonRecast\Node\ObjectItemNode;

$stringValues = [];

$finder->find(
    $document,
    static function (NodeJson $node) use (&$stringValues): bool {
        if ($node instanceof ObjectItemNode || $node instanceof ArrayItemNode || $node instanceof JsonDocument) {
            if ($node->value instanceof StringNode) {
                $stringValues[] = $node->value;
            }
        }

        return false;
    },
);
```

Matching on the *item* and reading its `value` property selects exactly the strings used as values, never as keys.

## Finder Reads, Visitors Write

`NodeJsonFinder` is a query helper. The returned nodes are live references into the tree, so mutating them works in memory — but it bypasses the `NodeChangeSet` that the preserving printer relies on:

```php
$constraint = $finder->findFirst($document, /* ... */);

$constraint->value = '^4.0'; // in memory only; the preserving printer keeps the original text
```

- **Find nodes:** `NodeJsonFinder`
- **Modify nodes:** `NodeJsonTraverser` with a visitor that returns the changed node

See [Traversal And Paths](traversal-and-paths.html) and [Editing And Printing](editing-and-printing.html) for the mutation workflow.
