<?php

namespace App\Http\Controllers\Lifecycle;

use App\Enums\I18n\Locale;
use App\Http\Controllers\Controller;
use App\Services\Lifecycle\StatusRegistry;
use Illuminate\Http\JsonResponse;

/**
 * The status catalogue for status badges: every entity's statuses in display
 * order with their tone and a label per supported locale, so a language
 * switch needs no new request. Entities without their own statuses use "*".
 */
class StatusCatalogueController extends Controller
{
    public function __invoke(StatusRegistry $registry): JsonResponse
    {
        $statuses = [];

        foreach (Locale::cases() as $locale) {
            foreach ($registry->toArray($locale->value) as $entity => $codes) {
                foreach ($codes as $code => $status) {
                    $statuses[$entity][$code]['tone'] = $status['tone'];
                    $statuses[$entity][$code]['label'][$locale->value] = $status['label'];
                }
            }
        }

        return response()->json(['statuses' => $statuses]);
    }
}
