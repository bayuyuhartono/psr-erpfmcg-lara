<?php

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Model;

abstract class IncrementingModel extends Model
{
    protected $guarded = [];
}
