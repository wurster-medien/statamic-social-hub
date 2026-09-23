<?php

use Illuminate\Support\Facades\Route;
use WursterMedien\SocialHub\Http\Controllers\CP\SocialHubController;

Route::prefix('social-hub')->name('social-hub.')->group(function () {
    Route::get('/', [SocialHubController::class, 'index'])->name('index');
    Route::post('sync', [SocialHubController::class, 'sync'])->name('sync');
});
