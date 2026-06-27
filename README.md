# JsonRecast

A PHP JSON parser that turns JSON into an editable AST, supports visitor-based traversal, and prints changes back while preserving the original formatting.

Inspired by PHP-Parser, built for tools that need to modify JSON files safely.

## Installation

```bash
composer require boundwize/jsonrecast
```

## Features

* Parse JSON into an AST
* Traverse and modify nodes with `NodeJsonTraverser`
* Create visitors with `NodeJsonVisitor`
* Access runtime traversal context with `NodeJsonPath`
* Replace, add, and remove JSON data
* Preserve original formatting when printing modified JSON
* Keep number representations like `1`, `1.0`, and `1e0`
* Supports recursive objects and arrays
* Tracks changes outside the AST
* Designed for tooling, codemods, config updates, and automated refactoring

## Example

```php
use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonRemoval;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;

$document = JsonRecast::parse($json);

$result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
    public function enterNode(NodeJson $node, NodeJsonPath $path): null|NodeJson|NodeJsonRemoval
    {
        if (! $node instanceof StringNode || ! $path->isObjectValue('name')) {
            return null;
        }

        return new StringNode('boundwize/jsonrecast');
    }
});

echo JsonRecast::print($result);
```

## Philosophy

Most JSON libraries are optimized for reading data.

JsonRecast is optimized for safely changing files. It keeps the original structure and formatting where possible, so automated tools can modify JSON without creating noisy diffs.

The AST stays clean. Change metadata lives in the traversal result.

## License

JsonRecast is released under the [MIT License](LICENSE).
