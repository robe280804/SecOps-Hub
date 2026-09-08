<?php

use App\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

Route::post('/api/v1/session', [SessionController::class, 'store'])
    ->middleware('throttle:login')->name('api.v1.session.store');
Route::delete('/api/v1/session', [SessionController::class, 'destroy'])
    ->middleware(['auth:web', 'throttle:api'])->name('api.v1.session.destroy');

Route::get('/', function () {
    return view('welcome');
});
