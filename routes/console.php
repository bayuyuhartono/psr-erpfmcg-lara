<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reservations that outlive their orders hold stock the warehouse physically
// has and cannot sell (schema §11).
Schedule::command('stock:release-expired-reservations')->hourly();
