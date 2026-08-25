<?php

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Model;

abstract class PivotModel extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
