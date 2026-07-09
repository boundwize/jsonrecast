---
title: Node Reference
layout: default
nav_order: 5
---

# Node Reference
{: .no_toc }

JsonRecast nodes are small typed PHP objects. Container nodes hold item nodes; item nodes hold values plus parsed whitespace used by the preserving printer.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Document

| Node | Purpose |
| --- | --- |
| `JsonDocument` | Wraps the root JSON value in `$value`. |

`JsonRecast::parse()` always returns a `JsonDocument`.

## Containers

| Node | Public data | Helpers |
| --- | --- | --- |
| `ObjectNode` | `list<ObjectItemNode> $items` | `get()`, `has()`, `set()`, `remove()` |
| `ArrayNode` | `list<ArrayItemNode> $items` | `append()`, `insert()`, `removeAt()` |

`ObjectNode::set()` accepts any `NodeJson` value:

```php
$object->set('name', new StringNode('boundwize/jsonrecast'));
$object->set('keywords', JsonValue::from(['json', 'ast']));
```

`ArrayNode` helpers also accept any `NodeJson` value:

```php
$array->append(JsonValue::from('last'));
$array->insert(0, JsonValue::from('first'));
$array->removeAt(1);
```

## Items

| Node | Purpose |
| --- | --- |
| `ObjectItemNode` | Holds a `StringNode $key`, a `NodeJson $value`, and object item whitespace. |
| `ArrayItemNode` | Holds a `NodeJson $value` and array item whitespace. |

Remove an item node when you want to delete a key/value pair or an array entry.

## Scalars

| Node | Public data |
| --- | --- |
| `StringNode` | `string $value` |
| `NumberNode` | `string $rawValue` |
| `BooleanNode` | `bool $value` |
| `NullNode` | No value property |

`NumberNode` stores the original spelling as `rawValue`, so `1`, `1.0`, and `1e0` remain distinct until you intentionally replace the node.

```php
$number = new NumberNode('1e0');

echo $number->rawValue;      // 1e0
echo $number->toIntOrFloat(); // 1.0
```

## Convert PHP Values

Use `JsonValue::from()` to create nodes from PHP values.

```php
use Boundwize\JsonRecast\Value\JsonValue;

$node = JsonValue::from([
    'name' => 'boundwize/jsonrecast',
    'keywords' => ['json', 'ast'],
    'private' => false,
    'license' => null,
]);
```

Supported inputs are strings, integers, floats, booleans, null, list arrays, and associative arrays.

## Node Attributes

Parsed nodes also carry metadata through the `NodeJson` attribute API:

```php
$node->getAttributes();
$node->getAttribute('originalText');
$node->setAttribute('custom', true);
$node->hasAttribute('custom');
$node->removeAttribute('custom');
```

Built-in attribute names are available in `Boundwize\JsonRecast\Attribute\NodeAttributes`:

| Attribute | Meaning |
| --- | --- |
| `START_OFFSET` | Start offset in the original source. |
| `END_OFFSET` | End offset in the original source. |
| `ORIGINAL_TEXT` | Exact source substring for the node. |
| `DEPTH` | Original nesting depth, where the root JSON value and document are depth `0`. |
| `SOURCE` | Full source string, stored on the document. |
| `NEWLINE` | Detected newline sequence, stored on the document and parsed nodes. |
| `INDENT` | Detected indentation unit, stored on the document and parsed nodes, and used when printing newly-created nested structures. |
| `TRAILING_NEWLINE` | Whether the document ended with a newline. |

Attributes are useful for tooling and debugging. JsonRecast does not use a mutable "has changed" node attribute; changes are tracked in the traversal result.
