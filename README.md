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

## Edit JSON content without noisy diffs

- Update JSON content programmatically and keep the original indentation, spacing, and key order intact.
- Transform documents with visitors, inspired by [nikic/PHP-Parser](https://github.com/nikic/PHP-Parser), with path awareness built in.
- The printed output differs from the input only where you made changes; nothing else is reformatted.

## Installation

```bash
composer require boundwize/jsonrecast
```

## Quick start

<p align="center">
  <img src="docs/assets/jsonrecast-demo-adaptive.svg" alt="Demo of JsonRecast bumping a dependency, adding a new one, and removing an entry in composer.json while preserving the original formatting, including non-standard alignment" width="732">
</p>

The demo above is a single visitor that bumps a dependency, adds a new one, and removes an entry:

```php
<?php

use Boundwize\JsonRecast\JsonRecast;
use Boundwize\JsonRecast\Node\NodeJson;
use Boundwize\JsonRecast\Node\ObjectItemNode;
use Boundwize\JsonRecast\Node\ObjectNode;
use Boundwize\JsonRecast\Node\StringNode;
use Boundwize\JsonRecast\NodePath\NodeJsonPath;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitor;
use Boundwize\JsonRecast\NodeVisitor\NodeJsonVisitorAbstract;

$document = JsonRecast::parse(file_get_contents('composer.json'));

$result = JsonRecast::traverse($document, new class extends NodeJsonVisitorAbstract {
    public function enterNode(NodeJson $node, NodeJsonPath $path): null|NodeJson|int
    {
        // ~ bump monolog to ^3.6
        if ($node instanceof ObjectItemNode && $path->matches(['require']) && $node->key->value === 'monolog/monolog') {
            $node->value = new StringNode('^3.6');

            return $node;
        }

        // + add guzzle under "require"
        if ($node instanceof ObjectNode && $path->matches(['require'])) {
            $node->set('guzzlehttp/guzzle', new StringNode('^7.8'));

            return $node;
        }

        // − drop the root "minimum-stability" entry
        if ($node instanceof ObjectItemNode && $path->isRoot() && $node->key->value === 'minimum-stability') {
            return NodeJsonVisitor::REMOVE_NODE;
        }

        return null;
    }
});

// update the file with original formatting preserved
file_put_contents('composer.json', JsonRecast::print($result));
```

Anything the visitor didn't touch keeps its original indentation, key order, and alignment.

## Documentation

Documentation is available online, with source files kept in this repository:

- Documentation site: <https://boundwize.github.io/jsonrecast/>
- Documentation source: [docs/](docs/index.md) for local edits and GitHub Pages publishing.
