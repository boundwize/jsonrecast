<?php

declare(strict_types=1);

namespace Boundwize\JsonRecast\Printer;

use function str_repeat;

final readonly class PrintContext
{
    public function __construct(
        private string $indent = '    ',
        public string $newline = "\n",
        private int $level = 0,
    ) {
    }

    public function next(): self
    {
        return new self($this->indent, $this->newline, $this->level + 1);
    }

    public function indentation(): string
    {
        return str_repeat($this->indent, $this->level);
    }

    public function childIndentation(): string
    {
        return str_repeat($this->indent, $this->level + 1);
    }
}
