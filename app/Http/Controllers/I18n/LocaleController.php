<?php

namespace App\Http\Controllers\I18n;

use App\Enums\I18n\Locale;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
        ]);

        $request->session()->put('locale', $data['locale']);

        return back(303);
    }
}
