---
title: Advanced Tricks
layout: default
nav_order: 7
---

# Advanced Tricks
{: .no_toc }

These tricks are useful when building project tooling: config upgraders, repository cleanup commands, package metadata migrations, or tests that need to prove a JSON rewrite changed only what it meant to change.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Choose Direct Edits Or Visitors

Use direct `JsonDocument` edits when the tool already knows the small part of the file it wants to change. This is a good fit for root-level config migration, duplicate-key cleanup, or moving one known subtree.

```php
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Value\JsonValue;

$document = JsonRecast::parse($json);

if ($document->value instanceof ObjectNode) {
    $document->value->set('config', JsonValue::from(['sort-packages' => true]));
}

echo JsonRecast::print($document);
```

Use a visitor when the tool should find matching nodes while walking the document. This is the better fit for nested paths, repeated structures, removals with `NodeJsonVisitor::REMOVE_NODE`, or edits that depend on traversal timing.

```php
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\JsonRecast\Value\JsonValue;

$result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
    public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
    {
        if (! $node instanceof ObjectNode || ! $path->isRoot()) {
            return null;
        }

        if ($node->has('config')) {
            return null;
        }

        $node->set('config', JsonValue::from(['sort-packages' => true]));

        return $node;
    }
});

echo JsonRecast::print($result);
```

In short: direct edits are simplest when the location is already known; visitors are safer when discovery, path checks, or traversal order are part of the work. When a visitor finds the file is already in the intended state, return `null`; that avoids marking the node as changed on repeated runs. Use `leaveNode()` when it follows an earlier `enterNode()` change or when the decision depends on child edits that have already happened.

## Let The Parsed Style Shape New Values

Project tools often need to add the same data to files with different local styles. Build the new value once; the preserving printer uses the parsed document's indentation and newline metadata when it has to print that new subtree.

```php
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Value\JsonValue;

$document = JsonRecast::parse(<<<'JSON'
{
  "name": "acme/demo",
  "config": {
    "allow-plugins": false
  }
}
JSON);

if ($document->value instanceof ObjectNode) {
    $document->value->set('config', JsonValue::from([
        'allow-plugins' => true,
        'sort-packages' => true,
    ]));
}

echo JsonRecast::print($document);
```

```json
{
  "name": "acme/demo",
  "config": {
    "allow-plugins": true,
    "sort-packages": true
  }
}
```

The migration code does not need to know whether the source uses two spaces, four spaces, tabs, or inline objects. In this example the replacement follows the document's two-space indentation because that is what was parsed from the surrounding file.

## Collapse Duplicate Keys

JSON parsers usually treat the last duplicate object key as the effective value. JsonRecast keeps object items visible, so tooling can clean up duplicates while making the intended value explicit.

```php
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;

$document = JsonRecast::parse('{"name":"acme/demo","license":"GPL","license":"MIT"}');

if ($document->value instanceof ObjectNode) {
    $document->value->set('license', new StringNode('BSD-3-Clause'));
}

echo JsonRecast::print($document);
```

```json
{"name":"acme/demo","license":"BSD-3-Clause"}
```

`ObjectNode::set()` updates the effective value and removes the stale duplicate entries. Use this in migration tools when duplicate keys would otherwise make a config file ambiguous.

## Move Existing Subtrees

When a value is already present in the file, move the existing node instead of rebuilding it from PHP data. This keeps useful details such as number spelling, escaped strings, and multiline formatting.

```php
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;

$document = JsonRecast::parse(<<<'JSON'
{
  "legacy": [
    {
      "path": "tests/Fixtures/App"
    }
  ],
  "autoload-dev": []
}
JSON);

if ($document->value instanceof ObjectNode) {
    $legacyItem = $document->value->get('legacy');
    $autoloadDevItem = $document->value->get('autoload-dev');

    if (
        $legacyItem instanceof ObjectItemNode
        && $legacyItem->value instanceof ArrayNode
        && $autoloadDevItem instanceof ObjectItemNode
        && $autoloadDevItem->value instanceof ArrayNode
    ) {
        $movedNode = $legacyItem->value->items[0]->value;

        $legacyItem->value->removeAt(0);
        $autoloadDevItem->value->append($movedNode);
    }
}

echo JsonRecast::print($document);
```

```json
{
  "legacy": [
  ],
  "autoload-dev": [
    {
      "path": "tests/Fixtures/App"
    }
  ]
}
```

This is handy for project restructures: move a block from an old key to a new key, then clean up the empty parent in a later visitor if your tool wants to remove it.

## Use LeaveNode After Array Reindexing

If a migration removes or inserts array items in `enterNode()`, child values are visited with the updated indexes. Use `leaveNode()` in the same visitor when the next edit should target the reshaped array.

```php
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\ArrayNode;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use Boundwize\JsonRecast\Value\JsonValue;

$document = JsonRecast::parse(<<<'JSON'
{
    "repositories": [
        {"type": "vcs", "url": "https://example.com/old.git"},
        {"type": "path", "url": "packages/local"}
    ]
}
JSON);

$result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
    public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
    {
        if (! $node instanceof ArrayNode || ! $path->matchesObjectKeys(['repositories'])) {
            return null;
        }

        $oldIndex = null;
        $hasComposerRepository = false;

        foreach ($node->items as $index => $item) {
            if (! $item->value instanceof ObjectNode) {
                continue;
            }

            $type = $item->value->get('type');
            $url = $item->value->get('url');

            if (
                $type instanceof ObjectItemNode
                && $type->value instanceof StringNode
                && $type->value->value === 'vcs'
                && $url instanceof ObjectItemNode
                && $url->value instanceof StringNode
                && $url->value->value === 'https://example.com/old.git'
            ) {
                $oldIndex = $index;
            }

            if (
                $type instanceof ObjectItemNode
                && $type->value instanceof StringNode
                && $type->value->value === 'composer'
                && $url instanceof ObjectItemNode
                && $url->value instanceof StringNode
                && $url->value->value === 'https://repo.packagist.org'
            ) {
                $hasComposerRepository = true;
            }
        }

        if ($oldIndex === null && $hasComposerRepository) {
            return null;
        }

        if ($oldIndex !== null) {
            $node->removeAt($oldIndex);
        }

        if ($hasComposerRepository) {
            return $node;
        }

        $node->append(JsonValue::from([
            'type' => 'composer',
            'url' => 'https://repo.packagist.org',
        ]));

        return $node;
    }

    public function leaveNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
    {
        if ($node instanceof ObjectNode && $path->matches(['repositories', 1])) {
            if ($node->has('canonical')) {
                return null;
            }

            $node->set('canonical', JsonValue::from(false));

            return $node;
        }

        return null;
    }
});

echo JsonRecast::print($result);
```

```json
{
    "repositories": [
        {"type": "path", "url": "packages/local"},
        {
            "type": "composer",
            "url": "https://repo.packagist.org",
            "canonical": false
        }
    ]
}
```

The `repositories` array is changed before its children are traversed. By the time `leaveNode()` sees the new repository object, its path is `['repositories', 1]`, so the visitor can add metadata to the reshaped item directly. The early `return null` branches make the migration repeatable: once the old repository is gone and `canonical` already exists, the visitor stops without appending or marking the node changed again.

## Preserve Special Number Spelling

`NumberNode` stores the raw JSON spelling. If your tool only needs to inspect a number, avoid rebuilding the node, because spellings such as `-0`, `1.0`, and `1e0` may matter to users.

```php
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\NumberNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;
use RuntimeException;

$document = JsonRecast::parse('{"temperature_delta":-0}');

$result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
    public function enterNode(NodeJson $node, NodeJsonPath $path): ?NodeJson
    {
        if ($node instanceof NumberNode) {
            $value = $node->toIntOrFloat();

            if (
                $path->matchesObjectKeys(['temperature_delta'])
                && ($value < -10 || $value > 10)
            ) {
                throw new RuntimeException('temperature_delta must be between -10 and 10.');
            }
        }

        return null;
    }
});

echo JsonRecast::print($result);
```

```json
{"temperature_delta":-0}
```

Rebuild number nodes only when the migration intentionally changes the number. Otherwise, leave the original node in place and the preserving printer will reuse its original text.
