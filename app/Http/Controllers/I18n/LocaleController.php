<?php

namespace App\Http\Controllers\I18n;

use App\Enums\I18n\Locale;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['locale' => app()->getLocale()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
        ]);

        $request->session()->put('locale', $data['locale']);

        return response()->json(['locale' => $data['locale']]);
    }
}
