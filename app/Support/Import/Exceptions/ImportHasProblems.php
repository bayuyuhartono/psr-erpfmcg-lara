<?php

namespace App\Support\Import\Exceptions;

use App\Support\Import\RowProblem;
use RuntimeException;

final class ImportHasProblems extends RuntimeException
{
    /** @param  list<RowProblem>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(sprintf(
            '%d row(s) cannot be imported. Nothing was saved.',
            count($problems),
        ));
    }
}
