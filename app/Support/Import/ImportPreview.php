<?php

namespace App\Support\Import;

final readonly class ImportPreview
{
    /**
     * @param  list<RowProblem>  $problems
     * @param  list<ImportRow>  $sample  the first few rows, to show what was read
     */
    public function __construct(
        public int $rowCount,
        public array $problems,
        public array $sample,
    ) {}

    public function isImportable(): bool
    {
        return $this->rowCount > 0 && $this->problems === [];
    }
}
