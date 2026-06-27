# JsonRecast

A PHP JSON parser that turns JSON into an editable AST, supports visitor-based traversal, and prints changes back while preserving the original formatting.

Inspired by [PHP-Parser](https://github.com/nikic/PHP-Parser/), built for tools that need to modify JSON files safely.

JsonRecast is optimized for safely changing files. It keeps the original structure and formatting where possible, so automated tools can modify JSON without creating noisy diffs.

The AST stays clean. Change metadata lives in the traversal result.

[![Latest Version](https://img.shields.io/github/release/boundwize/jsonrecast.svg?style=flat-square)](https://github.com/boundwize/jsonrecast/releases)
[![ci build](https://github.com/boundwize/jsonrecast/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/boundwize/jsonrecast/actions/workflows/ci.yml)
[![Code Coverage](https://codecov.io/gh/boundwize/jsonrecast/branch/main/graph/badge.svg)](https://codecov.io/gh/boundwize/jsonrecast)
[![PHPStan](https://img.shields.io/badge/style-level%20max-brightgreen.svg?style=flat-square&label=phpstan)](https://github.com/phpstan/phpstan)
[![Downloads](https://poser.pugx.org/boundwize/jsonrecast/downloads)](https://packagist.org/packages/boundwize/jsonrecast)

![Windows](https://img.shields.io/badge/Windows-supported-0078D6?logo=windows&logoColor=white&labelColor=555555)
![macOS](https://img.shields.io/badge/macOS-supported-C084FC?logo=apple&logoColor=white&labelColor=555555)
![Linux](https://img.shields.io/badge/Linux-supported-FCC624?logo=linux&logoColor=black&labelColor=555555)

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