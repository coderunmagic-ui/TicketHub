<?php

namespace App\Http\Controllers;

use App\Models\TicketType;
use App\Services\AdmissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaitingRoomController extends Controller
{
    public function show(TicketType $ticketType): View
    {
        abort_unless(config('admission.enabled') && $ticketType->event->published, 404);

        return view('waiting.show', ['type' => $ticketType->load('event')]);
    }

    public function join(Request $request, TicketType $ticketType, AdmissionService $admission): JsonResponse
    {
        abort_unless(config('admission.enabled'), 404);
        $event = $ticketType->event;
        abort_unless($event->published && now()->gte($event->opens_at) && now()->lt($event->closes_at), 422, '目前不在開放報名時間。');
        $state = $admission->join((int) $request->user()->id, (int) $ticketType->id);

        return response()->json($state, match ($state['state']) {
            'full' => 429,'conflict' => 409,default => 200
        })->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, AdmissionService $admission): JsonResponse
    {
        return response()->json($admission->status($this->token($request)))->header('Cache-Control', 'no-store');
    }

    public function leave(Request $request, AdmissionService $admission): JsonResponse
    {
        return response()->json($admission->leave($this->token($request)))->header('Cache-Control', 'no-store');
    }

    private function token(Request $request): string
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) <= 4096, 403, '缺少候位憑證。');

        return $token;
    }

    public function metrics(AdmissionService $admission): JsonResponse
    {
        return response()->json($admission->metrics())->header('Cache-Control', 'no-store');
    }
}
