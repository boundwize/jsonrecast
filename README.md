# JsonRecast

<p align="center">
    <img alt="JsonRecast Logo" src="docs/assets/jsonrecast-adaptive.svg" width="300">
</p>

<p align="center">
    Editable JSON AST with visitor traversal and formatting-preserving printing.
</p>

[![Latest Version](https://img.shields.io/github/release/boundwize/jsonrecast.svg?style=flat-square)](https://github.com/boundwize/jsonrecast/releases)
[![ci build](https://github.com/boundwize/jsonrecast/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/boundwize/jsonrecast/actions/workflows/ci.yml)
[![Documentation](https://img.shields.io/badge/docs-GitHub%20Pages-0969da?style=flat-square)](https://boundwize.github.io/jsonrecast/)
[![Code Coverage](https://codecov.io/gh/boundwize/jsonrecast/branch/main/graph/badge.svg)](https://codecov.io/gh/boundwize/jsonrecast)
[![PHPStan](https://img.shields.io/badge/style-level%20max-brightgreen.svg?style=flat-square&label=phpstan)](https://github.com/phpstan/phpstan)
[![Downloads](https://poser.pugx.org/boundwize/jsonrecast/downloads)](https://packagist.org/packages/boundwize/jsonrecast)

![Windows](https://img.shields.io/badge/Windows-supported-0078D6?logo=windows&logoColor=white&labelColor=555555)
![macOS](https://img.shields.io/badge/macOS-supported-C084FC?logo=apple&logoColor=white&labelColor=555555)
![Linux](https://img.shields.io/badge/Linux-supported-FCC624?logo=linux&logoColor=black&labelColor=555555)

JsonRecast parses JSON into an editable AST, lets you transform it with path-aware visitors, and prints the result while keeping the original formatting where possible. Its traversal model is inspired by [nikic/PHP-Parser](https://github.com/nikic/PHP-Parser), applied to JSON documents for tools that update files without creating noisy diffs.

## Installation

```bash
composer require boundwize/jsonrecast
```

## Example

This example updates a `composer.json`-style document in one traversal:

1. Parse the JSON into a `JsonDocument`.
2. Traverse the document with a visitor and return changed nodes.
3. Replace the root `name` value.
4. Remove the root `minimum-stability` entry.
5. Add a PSR-4 namespace under `autoload.psr-4`.
6. Remove a stale value from `autoload-dev.classmap`.
7. In `leaveNode()`, remove the now-empty `autoload-dev` parent.
8. Print the result while preserving the original formatting style.

```php
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;

$json = <<<'JSON'
{
    "name": "acme/demo",
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "autoload-dev": {
        "classmap": [
            "tests/Fixtures/App"
        ]
    },
    "minimum-stability": "dev"
}
JSON;

// 1. Parse the source JSON into an editable document node.
$document = JsonRecast::parse($json);

// 2. Traverse the document and return changed nodes so JsonRecast can track edits.
$result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
    public function enterNode(NodeJson $node, NodeJsonPath $path): null|NodeJson|int
    {
        if ($node instanceof ObjectItemNode && $path->isRoot()) {
            // 3. Replace the root "name" value.
            if ($node->key->value === 'name') {
                $node->value = new StringNode('boundwize/jsonrecast');

                return $node;
            }

            // 4. Remove an unwanted root object item.
            if ($node->key->value === 'minimum-stability') {
                return NodeJsonVisitor::REMOVE_NODE;
            }
        }

        // 5. Add a new namespace under "autoload.psr-4".
        if ($node instanceof ObjectNode && $path->matches(['autoload', 'psr-4'])) {
            $node->set('Boundwize\\JsonRecast\\', new StringNode('src/'));

            return $node;
        }

        // 6. Remove a stale classmap entry.
        if ($node instanceof ArrayNode && $path->matches(['autoload-dev', 'classmap'])) {
            foreach ($node->items as $index => $item) {
                if ($item->value instanceof StringNode && $item->value->value === 'tests/Fixtures/App') {
                    $node->removeAt($index);

                    return $node;
                }
            }
        }

        return null;
    }

    // 7. After child edits are done, remove the empty "autoload-dev" parent.
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
});

// 8. Print with the original formatting preserved where possible.
echo JsonRecast::print($result);
```

That will output:

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

## Finding Nodes

When you only need to inspect or locate nodes, `NodeJsonFinder` removes the visitor boilerplate:

```php
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeJsonFinder;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;

$finder = new NodeJsonFinder();

// first node matching a filter; the path comes free with the callback
$autoload = $finder->findFirst(
    $document,
    static fn (NodeJson $node, NodeJsonPath $path): bool =>
        $node instanceof ObjectNode
        && $path->matches(['autoload', 'psr-4']),
);

// all nodes of a given class
$stringNodes = $finder->findInstanceOf($document, StringNode::class);
```

`findFirst()` stops traversing as soon as the filter matches. The finder is read-only by design: find nodes with `NodeJsonFinder`, modify them with a `NodeJsonTraverser` visitor so changes are tracked for the preserving printer.

## Documentation

Read the full documentation at <https://boundwize.github.io/jsonrecast/>.
