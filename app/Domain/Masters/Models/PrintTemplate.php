<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\DomainModel;

final class PrintTemplate extends DomainModel
{
    protected $table = 'print_templates';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
