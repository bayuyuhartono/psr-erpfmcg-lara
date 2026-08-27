<?php

use App\Support\Localisation\Locales;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Switching language.
 *
 * A plain redirect rather than a Livewire action: the switcher has to work on
 * the login screen, where the only Livewire component on the page is the login
 * form itself, and adding one there to change a language would put it inside
 * the thing that authenticates.
 */
Route::get('locale/{locale}', function (string $locale) {
    Locales::remember($locale);

    return back();
})->name('locale.switch')->middleware(['web']);
