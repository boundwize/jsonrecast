<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer;

use function str_repeat;

final readonly class PrintContext
{
    private string $indentation;

    public function __construct(
        private string $indent = '    ',
        public string $newline = "\n",
        private int $level = 0,
    ) {
        $this->indentation = str_repeat($indent, $level);
    }

    public function next(): self
    {
        return new self($this->indent, $this->newline, $this->level + 1);
    }

    public function indentation(): string
    {
        return $this->indentation;
    }

    public function childIndentation(): string
    {
        return $this->indentation . $this->indent;
    }

    public function indentUnit(): string
    {
        return $this->indent;
    }

    public function level(): int
    {
        return $this->level;
    }
}
