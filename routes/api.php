<?php

use App\Http\Controllers\WaitingRoomController;
use Illuminate\Support\Facades\Route;

// Stateless capability endpoints: no web session, auth provider, model binding, or SQL queries.
Route::post('/admission/status', [WaitingRoomController::class, 'status'])->middleware('throttle:waiting-poll')->name('waiting.status');
Route::post('/admission/leave', [WaitingRoomController::class, 'leave'])->middleware('throttle:waiting-poll')->name('waiting.leave');
