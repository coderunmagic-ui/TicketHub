<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireAdmin
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless($r->user()?->is_admin, 403);

        return $next($r);
    }
}
