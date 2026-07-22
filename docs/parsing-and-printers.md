---
title: Parsing And Printers
layout: default
nav_order: 7
---

# Parsing And Printers
{: .no_toc }

JsonRecast separates parsing, format-preserving printing, and normalized pretty printing.

## Contents
{: .no_toc }

1. TOC
{:toc}

## Parse JSON

The facade is the shortest path:

```php
use Boundwize\JsonRecast\JsonRecast;

$document = JsonRecast::parse($source);
```

You can also instantiate the parser directly:

```php
use Boundwize\JsonRecast\Parser\JsonParser;

$document = (new JsonParser())->parse($source);
```

Invalid input throws `ParseError`.

```php
use Boundwize\JsonRecast\Parser\ParseError;

try {
    $document = JsonRecast::parse('{"name":}');
} catch (ParseError $error) {
    printf(
        "Invalid JSON at line %d, column %d",
        $error->sourceLine,
        $error->column,
    );
}
```

`ParseError` also exposes the zero-based source `$offset`.

## Maximum Depth

JsonRecast limits JSON nesting depth to `512` by default, matching PHP's `json_decode()` default depth. The same default applies when parsing JSON, building nodes from PHP values, and printing node trees, so default-generated output remains parseable by the default parser.

If parsed input exceeds the configured limit, parsing throws a catchable `ParseError` with the message `Maximum stack depth exceeded.` If PHP value conversion or printing exceeds the configured limit, JsonRecast throws `InvalidArgumentException` with the same message.

You can raise or lower the limit when parsing through the facade:

```php
use Boundwize\JsonRecast\JsonRecast;

$document = JsonRecast::parse($source, maximumDepth: 1024);
```

Or when using `JsonParser` directly:

```php
use Boundwize\JsonRecast\Parser\JsonParser;

$document = (new JsonParser(maximumDepth: 1024))->parse($source);
```

The same option is available when converting PHP values:

```php
use Boundwize\JsonRecast\Value\JsonValue;

$node = JsonValue::from($value, maximumDepth: 1024);
```

And when printing:

```php
use Boundwize\JsonRecast\Printer\JsonPreservingPrinter;
use Boundwize\JsonRecast\Printer\JsonPrettyPrinter;

$json = JsonRecast::print($document, maximumDepth: 1024);

$preserved = (new JsonPreservingPrinter(maximumDepth: 1024))->print($document);
$pretty = (new JsonPrettyPrinter(maximumDepth: 1024))->print($document);
```

The maximum depth must be greater than `0`.

## Preserving Printer

`JsonRecast::print()` uses `JsonPreservingPrinter`. When it receives a parsed document without changes, it can return the original text exactly.

```php
$document = JsonRecast::parse("{\r\n  \"name\" : \"jsonrecast\"\r\n}\r\n");

echo JsonRecast::print($document);
```

When it receives a `JsonRecastResult`, it uses the result change set so changed nodes are rebuilt while unchanged nodes reuse original text.

```php
$result = JsonRecast::traverse($document, $visitor);

echo JsonRecast::print($result);
```

The preserving printer keeps the document newline style and trailing newline when they were present in the parsed source.

## Pretty Printer

Use `JsonPrettyPrinter` when you want normalized output instead of format preservation.

```php
use Boundwize\JsonRecast\Printer\JsonPrettyPrinter;

$printer = new JsonPrettyPrinter(indent: '  ');

echo $printer->print($document);
```

Pretty output uses consistent indentation, one object property or array value per line, and no trailing newline.

## Printer Interface

Both printers implement `JsonPrinter`:

```php
use Boundwize\JsonRecast\Printer\JsonPrinter;

function render(JsonPrinter $printer, NodeJson $node): string
{
    return $printer->print($node);
}
```

Use `JsonPreservingPrinter` for source-to-source transformations. Use `JsonPrettyPrinter` for generated JSON or tests where stable normalized output is easier to assert.
