<?php

use App\Http\Controllers\I18n\LocaleController;
use App\Http\Controllers\Lifecycle\StatusCatalogueController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'v1', 'middleware' => 'web'], function (): void {
    Route::get('locale', [LocaleController::class, 'show'])->name('api.locale.show');
    Route::put('locale', [LocaleController::class, 'update'])->name('api.locale.update');
    Route::get('statuses', StatusCatalogueController::class)->name('api.statuses.index');
});
