<?php

namespace App\Domain\Masters\Models;

use App\Domain\Shared\Models\IncrementingModel;

final class AuditLog extends IncrementingModel
{
    protected $table = 'audit_logs';

    public $incrementing = true;

    protected $keyType = 'int';

    public const UPDATED_AT = null;
}
