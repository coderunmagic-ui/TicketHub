<?php

namespace App\Http\Controllers;

use App\Contracts\CheckoutGateway;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\BookingService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $r)
    {
        return view('orders.index', ['orders' => Order::where('user_id', $r->user()->id)->with('ticketType.event')->latest()->paginate(20)]);
    }

    public function store(Request $r, string $ticketType, BookingService $service)
    {
        $data = $r->validate(['request_key' => 'required|uuid', 'quantity' => 'required|integer|min:1|max:20', 'attendee_name' => 'required|string|max:100', 'phone' => 'required|string|max:40', 'contact_email' => 'required|email|max:254']);
        $order = $service->book($r->user(), TicketType::findOrFail($ticketType), $data);

        return redirect()->route('orders.show', $order);
    }

    public function show(Request $r, Order $order, BookingService $service)
    {
        abort_unless($order->user_id === $r->user()->id, 403);
        $order = $service->expire($order);

        return view('orders.show', ['order' => $order->load('ticketType.event', 'tickets')]);
    }

    public function cancel(Request $r, Order $order, BookingService $service)
    {
        abort_unless($order->user_id === $r->user()->id, 403);
        $service->cancel($order);

        return back()->with('status', '訂單狀態已更新。');
    }

    public function pay(Request $r, Order $order, CheckoutGateway $gateway)
    {
        abort_unless($order->user_id === $r->user()->id, 403);

        return $gateway->checkout($order);
    }

    public function qr(Request $r, Ticket $ticket)
    {
        abort_unless($ticket->order->user_id === $r->user()->id && $ticket->order->status === 'confirmed', 403);
        $writer = new Writer(new ImageRenderer(new RendererStyle(260), new SvgImageBackEnd));

        return response($writer->writeString(route('admin.scan', ['token' => $ticket->token])), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-store']);
    }
}
