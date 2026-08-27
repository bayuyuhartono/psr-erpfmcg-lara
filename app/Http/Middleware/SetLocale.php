<?php

namespace App\Http\Middleware;

use App\Support\Localisation\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the chosen language to the request.
 *
 * Runs inside the panel's middleware stack, after the session has started —
 * without a session there is nothing to read, and the locale would silently
 * fall back to the default on every request.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(Locales::fromSession());

        return $next($request);
    }
}
