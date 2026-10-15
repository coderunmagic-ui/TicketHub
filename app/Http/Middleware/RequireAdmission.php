<?php

namespace App\Http\Middleware;

use App\Services\AdmissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmission
{
    public function __construct(private AdmissionService $admission) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('admission.enabled')) {
            return $next($request);
        }
        $token = $request->input('queue_token');
        abort_unless(is_string($token) && strlen($token) <= 4096, 429, '請先進入候位室，取得放行資格。');
        $lease = $this->admission->claim($token, (int) $request->user()->id, (int) $request->route('ticketType'));
        try {
            return $next($request);
        } finally {
            // A Redis outage after SQL commit must not turn a confirmed order into an apparent failure.
            try {
                $this->admission->release($lease);
            } catch (\Throwable $error) { /* Already reported; the processing lease expires automatically. */
            }
        }
    }
}
