<?php

namespace App\Support\Import;

final readonly class RowProblem
{
    public function __construct(
        public int $line,
        public ?string $column,
        public string $message,
    ) {}

    public function describe(): string
    {
        return $this->column === null
            ? sprintf('Row %d: %s', $this->line, $this->message)
            : sprintf('Row %d, %s: %s', $this->line, $this->column, $this->message);
    }
}
