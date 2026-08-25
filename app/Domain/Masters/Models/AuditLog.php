<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\IncrementingModel;

final class AuditLog extends IncrementingModel
{
    public $incrementing = true;

    protected $keyType = 'int';

    public const UPDATED_AT = null;
}
