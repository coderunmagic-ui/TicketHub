<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\BookingService;
use App\Services\EventCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function index()
    {
        return view('admin.index', ['events' => Event::with('ticketTypes')->latest()->paginate(20),
            'confirmed' => Order::where('status', 'confirmed')->sum('quantity'),
            'pending' => Order::where('status', 'pending')->count()]);
    }

    private function eventData(Request $r): array
    {
        return $r->validate(['title' => 'required|string|max:200', 'description' => 'required|string|max:10000', 'location' => 'required|string|max:200',
            'opens_at' => 'required|date', 'closes_at' => 'required|date|after:opens_at|before_or_equal:starts_at',
            'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'published' => 'required|boolean']);
    }

    public function create()
    {
        return view('admin.event', ['event' => new Event]);
    }

    public function store(Request $r)
    {
        $event = Event::create($this->eventData($r));

        app(EventCatalog::class)->invalidate($event->id);

        return redirect()->route('admin.events.edit', $event)->with('status', '活動已建立，請新增票種。');
    }

    public function edit(Event $event)
    {
        return view('admin.event', ['event' => $event->load('ticketTypes')]);
    }

    public function update(Request $r, Event $event)
    {
        $data = $this->eventData($r);
        DB::transaction(function () use ($event, $data, $r) {
            // All ticket rows are locked so publication/time edits cannot race with new bookings.
            TicketType::where('event_id', $event->id)->orderBy('id')->lockForUpdate()->get();
            $event->update($data);
            AuditLog::create(['user_id' => $r->user()->id, 'action' => 'event.updated', 'context' => ['event_id' => $event->id]]);
        }, 5);

        app(EventCatalog::class)->invalidate($event->id);

        return back()->with('status', '活動已更新。既有訂單的保留期限不變。');
    }

    public function ticketType(Request $r, Event $event)
    {
        $data = $r->validate(['name' => 'required|string|max:100', 'price' => 'required|integer|min:0|max:1000000', 'capacity' => 'required|integer|min:1|max:1000000', 'per_user_limit' => 'required|integer|min:1|max:20']);
        $event->ticketTypes()->create(['name' => $data['name'], 'price_cents' => $data['price'] * 100, 'capacity' => $data['capacity'], 'remaining' => $data['capacity'], 'per_user_limit' => $data['per_user_limit']]);

        app(EventCatalog::class)->invalidate($event->id);

        return back()->with('status', '票種已新增。');
    }

    public function capacity(Request $r, TicketType $ticketType)
    {
        $data = $r->validate(['capacity' => 'required|integer|min:1|max:1000000']);
        DB::transaction(function () use ($ticketType, $data, $r) {
            $type = TicketType::whereKey($ticketType->id)->lockForUpdate()->firstOrFail();
            $used = $type->capacity - $type->remaining;
            if ($data['capacity'] < $used) {
                throw ValidationException::withMessages(['capacity' => '總名額不能低於已售出及保留的數量。']);
            }
            $type->remaining = $data['capacity'] - $used;
            $type->capacity = $data['capacity'];
            $type->save();
            AuditLog::create(['user_id' => $r->user()->id, 'action' => 'capacity.updated', 'context' => ['ticket_type_id' => $type->id, 'capacity' => $type->capacity]]);
        }, 5);

        app(EventCatalog::class)->invalidate($ticketType->event_id);

        return back()->with('status', '名額已更新。');
    }

    private function orderQuery(Request $r)
    {
        $data = $r->validate(['event_id' => 'nullable|integer|exists:events,id', 'status' => 'nullable|in:pending,confirmed,expired,cancelled']);

        return Order::with('ticketType.event', 'user')->when($data['event_id'] ?? null, fn ($q, $id) => $q->whereHas('ticketType', fn ($t) => $t->where('event_id', $id)))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
    }

    public function orders(Request $r)
    {
        return view('admin.orders', ['orders' => $this->orderQuery($r)->latest()->paginate(30)->withQueryString(), 'events' => Event::orderBy('title')->get()]);
    }

    public function export(Request $r)
    {
        $query = $this->orderQuery($r);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['訂單', '活動', '票種', '姓名', 'Email', '電話', '張數', '金額 TWD', '狀態'], ',', '"', '');
            foreach ($query->lazyById(500) as $o) {
                $row = [$o->number, $o->ticketType->event->title, $o->ticketType->name, $o->attendee_name, $o->contact_email, $o->phone, $o->quantity, number_format($o->amount_cents / 100, 2, '.', ''), $o->statusLabel()];
                $row = array_map(fn ($v) => preg_match('/^\s*[=+@\-]|^[\t\r\n]/u', (string) $v) ? "'".$v : $v, $row);
                fputcsv($out, $row, ',', '"', '');
            } fclose($out);
        }, 'orders-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function scan(Request $r)
    {
        $token = $r->query('token');
        $ticket = is_string($token) ? Ticket::where('token', $token)->with('order.ticketType.event')->first() : null;

        return view('admin.scan', compact('ticket', 'token'));
    }

    public function checkIn(Request $r, BookingService $service)
    {
        $data = $r->validate(['token' => 'required|string|size:64']);
        $service->checkIn($data['token'], $r->user());

        return redirect()->route('admin.scan', ['token' => $data['token']])->with('status', '核銷成功。');
    }
}
