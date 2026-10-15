<?php

namespace App\Providers;

use App\Contracts\CheckoutGateway;
use App\Services\MockCheckoutGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CheckoutGateway::class, MockCheckoutGateway::class);
    }

    public function boot(): void
    {
        RateLimiter::for('waiting-poll', fn (Request $r) => [
            Limit::perMinute(30)->by('poll:'.hash('sha256', (string) $r->bearerToken())),
            Limit::perMinute(max(1, config('traffic.poll_ip')))->by('poll-ip:'.$r->ip()),
        ]);
        RateLimiter::for('booking', fn (Request $r) => [Limit::perMinute(20)->by('book-user:'.$r->user()->id), Limit::perMinute(max(1, config('traffic.booking_ip')))->by('book-ip:'.$r->ip())]);
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(5)->by('login:'.hash('sha256', mb_strtolower((string) $r->input('email')).'|'.$r->ip())), Limit::perMinute(max(1, config('traffic.login_ip')))->by('login-ip:'.$r->ip())]);
        Paginator::defaultView('pagination');
    }
}
