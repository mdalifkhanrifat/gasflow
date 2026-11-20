<?php

use App\Http\Controllers\I18n\LocaleController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'dashboards/platform/Index')->name('home');
Route::post('locale', LocaleController::class)->name('locale.update');
