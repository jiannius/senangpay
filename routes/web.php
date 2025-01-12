<?php

use Illuminate\Support\Facades\Route;

Route::prefix('__senangpay')->as('__senangpay')->withoutMiddleware('web')->group(function () {
    Route::get('redirect', [\App\Http\Controllers\SenangpayController::class, 'redirect'])->name('.redirect');
    Route::get('recurring', [\App\Http\Controllers\SenangpayController::class, 'recurring'])->name('.recurring');
    Route::post('webhook', [\App\Http\Controllers\SenangpayController::class, 'webhook'])->name('.webhook');
});
