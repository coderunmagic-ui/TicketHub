<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\WaitingRoomController;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireAdmission;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [EventController::class, 'index'])->name('home');
Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::view('/register', 'auth.register')->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::view('/forgot-password', 'auth.forgot')->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', fn (string $token, Request $r) => view('auth.reset', ['token' => $token, 'email' => $r->query('email')]))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::view('/email/verify', 'auth.verify')->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $r) {
        $r->fulfill();

        return redirect('/orders')->with('status', 'Email 已驗證。');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', function (Request $r) {
        if (! $r->user()->hasVerifiedEmail()) {
            $r->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', '驗證郵件已寄出。');
    })->middleware('throttle:3,1')->name('verification.send');
});
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/waiting/{ticketType}', [WaitingRoomController::class, 'show'])->name('waiting.show');
    Route::post('/waiting/{ticketType}/join', [WaitingRoomController::class, 'join'])->middleware('throttle:booking')->name('waiting.join');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('/ticket-types/{ticketType}/orders', [OrderController::class, 'store'])->middleware(['throttle:booking', RequireAdmission::class])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:booking')->name('orders.cancel');
    Route::post('/orders/{order}/mock-payment', [OrderController::class, 'pay'])->middleware('throttle:booking')->name('orders.pay');
    Route::get('/tickets/{ticket}/qr', [OrderController::class, 'qr'])->name('tickets.qr');
    Route::prefix('admin')->name('admin.')->middleware(RequireAdmin::class)->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/admission-metrics', [WaitingRoomController::class, 'metrics'])->name('admission.metrics');
        Route::get('/events/create', [AdminController::class, 'create'])->name('events.create');
        Route::post('/events', [AdminController::class, 'store'])->name('events.store');
        Route::get('/events/{event}/edit', [AdminController::class, 'edit'])->name('events.edit');
        Route::put('/events/{event}', [AdminController::class, 'update'])->name('events.update');
        Route::post('/events/{event}/ticket-types', [AdminController::class, 'ticketType'])->name('types.store');
        Route::put('/ticket-types/{ticketType}/capacity', [AdminController::class, 'capacity'])->name('types.capacity');
        Route::get('/orders', [AdminController::class, 'orders'])->name('orders');
        Route::get('/orders/export', [AdminController::class, 'export'])->name('export');
        Route::get('/scan', [AdminController::class, 'scan'])->name('scan');
        Route::post('/scan', [AdminController::class, 'checkIn'])->middleware('throttle:120,1')->name('checkin');
    });
});
