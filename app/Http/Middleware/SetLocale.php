<?php

namespace App\Http\Middleware;

use App\Enums\I18n\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $saved = $request->session()->get('locale');
        $locale = is_string($saved) ? Locale::tryFrom($saved) : null;

        App::setLocale(($locale ?? Locale::Bangla)->value);

        return $next($request);
    }
}
