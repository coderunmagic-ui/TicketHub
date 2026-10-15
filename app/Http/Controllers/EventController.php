<?php

namespace App\Http\Controllers;

use App\Services\EventCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request, EventCatalog $catalog): View
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1|max:10000']);

        return view('events.index', ['events' => $catalog->listing((int) ($data['page'] ?? 1))]);
    }

    public function show(string $event, EventCatalog $catalog): View
    {
        $record = $catalog->event((int) $event);
        abort_unless($record->published, 404);

        return view('events.show', ['event' => $record]);
    }
}
