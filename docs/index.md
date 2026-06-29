---
title: JsonRecast
nav_title: Home
description: Parse JSON into an editable AST, traverse it with visitors, and print changes while preserving the original formatting.
layout: default
nav_order: 1
---

<p class="jr-logo">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="{{ '/assets/jsonrecast-dark-mode.svg' | relative_url }}">
        <source media="(prefers-color-scheme: light)" srcset="{{ '/assets/jsonrecast-light-mode.svg' | relative_url }}">
        <img alt="JsonRecast Logo" src="{{ '/assets/jsonrecast-light-mode.svg' | relative_url }}">
    </picture>
</p>

<p align="center">
    Editable JSON AST with visitor traversal and formatting-preserving printing.
</p>

[![Latest Version](https://img.shields.io/github/release/boundwize/jsonrecast.svg?style=flat-square)](https://github.com/boundwize/jsonrecast/releases)
[![ci build](https://github.com/boundwize/jsonrecast/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/boundwize/jsonrecast/actions/workflows/ci.yml)
[![Code Coverage](https://codecov.io/gh/boundwize/jsonrecast/branch/main/graph/badge.svg)](https://codecov.io/gh/boundwize/jsonrecast)
[![PHPStan](https://img.shields.io/badge/style-level%20max-brightgreen.svg?style=flat-square&label=phpstan)](https://github.com/phpstan/phpstan)
[![Downloads](https://poser.pugx.org/boundwize/jsonrecast/downloads)](https://packagist.org/packages/boundwize/jsonrecast)

![Windows](https://img.shields.io/badge/Windows-supported-0078D6?logo=windows&logoColor=white&labelColor=555555)
![macOS](https://img.shields.io/badge/macOS-supported-C084FC?logo=apple&logoColor=white&labelColor=555555)
![Linux](https://img.shields.io/badge/Linux-supported-FCC624?logo=linux&logoColor=black&labelColor=555555)

JsonRecast is a PHP library for tools that need to read, edit, and rewrite JSON without causing noisy diffs. It parses JSON into node objects, lets visitors mutate or replace nodes, tracks the changed parts, then prints the document back with the original spacing and newline style where possible.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Why Use JsonRecast

- Build config migration tools that preserve a user's formatting choices.
- Traverse JSON as typed nodes instead of nested arrays.
- Use path-aware visitors for focused edits.
- Keep number spellings such as `1`, `1.0`, and `1e0`.
- Dump ASTs while writing visitors or debugging transformations.

## Where To Go Next

- [Quick Start](quick-start/) covers installation, parsing, traversal, and printing.
- [Editing And Printing](editing-and-printing/) shows object, array, and scalar edits that preserve formatting.
- [Traversal And Paths](traversal-and-paths/) explains visitor hooks, change tracking, and `NodeJsonPath`.
- [Node Reference](node-reference/) lists the node classes and helper methods.
- [Parsing And Printers](parsing-and-printers/) covers parse errors, preserving output, and pretty output.
- [AST Dumper](ast-dumper/) shows how to inspect parsed or transformed documents.
