<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\NotificationOutbox;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** MariaDB/InnoDB is the authority. All inventory writers lock ticket_type before order. */
class BookingService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['booking' => $message]);
    }

    private function log(string $action, Order $order, ?int $actor = null): void
    {
        AuditLog::create(['action' => $action, 'order_id' => $order->id, 'user_id' => $actor ?? $order->user_id]);
    }

    public function book(User $user, TicketType $selected, array $input): Order
    {
        return DB::transaction(function () use ($user, $selected, $input) {
            // Serialize this user's idempotency keys and quota checks across tabs / devices.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $type = TicketType::whereKey($selected->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$type->id, (int) $input['quantity'], $input['attendee_name'], $input['phone'], $input['contact_email']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $old = Order::where('user_id', $user->id)->where('request_key', $input['request_key'])->first();
            if ($old) {
                if (! hash_equals($old->request_hash, $hash)) {
                    $this->fail('此請求識別碼已用於不同內容，請重新開啟活動頁。');
                }

                return $old;
            }
            $event = $type->event;
            if (! $event->published || now()->lt($event->opens_at) || now()->gte($event->closes_at)) {
                $this->fail('目前不在開放報名時間。');
            }
            $quantity = (int) $input['quantity'];
            if ($quantity < 1 || $quantity > $type->per_user_limit) {
                $this->fail('超過每人限購數量。');
            }
            // Reclaim this ticket type's expired holds while its inventory row is locked.
            Order::where('ticket_type_id', $type->id)->where('status', 'pending')->where('expires_at', '<=', now())
                ->orderBy('id')->limit(20)->lockForUpdate()->get()->each(fn ($o) => $this->releaseLocked($o, $type, 'expired'));
            $held = Order::where('user_id', $user->id)->where('ticket_type_id', $type->id)
                ->whereIn('status', ['pending', 'confirmed'])->sum('quantity');
            if ($held + $quantity > $type->per_user_limit) {
                $this->fail('已報名或保留的票數加總超過每人限額。');
            }
            if ($type->remaining < $quantity) {
                $this->fail('名額不足或已售完。');
            }
            $type->remaining -= $quantity;
            $type->save();
            $amount = $type->price_cents * $quantity;
            $order = Order::create([
                'number' => (string) Str::uuid(), 'user_id' => $user->id, 'ticket_type_id' => $type->id,
                'request_key' => $input['request_key'], 'request_hash' => $hash, 'quantity' => $quantity,
                'amount_cents' => $amount, 'currency' => 'TWD', 'status' => $amount === 0 ? 'confirmed' : 'pending',
                'attendee_name' => $input['attendee_name'], 'phone' => $input['phone'], 'contact_email' => $input['contact_email'],
                'expires_at' => $amount === 0 ? null : now()->addMinutes(max(1, config('ticketing.hold_minutes')))->min($event->closes_at),
                'confirmed_at' => $amount === 0 ? now() : null,
            ]);
            if ($amount === 0) {
                $this->issueTickets($order);
            }
            $this->log('order.created', $order);

            return $order;
        }, 5);
    }

    private function issueTickets(Order $order): void
    {
        NotificationOutbox::create(['order_id' => $order->id]);
        for ($i = 0; $i < $order->quantity; $i++) {
            $order->tickets()->create(['token' => Str::random(64)]);
        }
    }

    private function releaseLocked(Order $order, TicketType $type, string $status): void
    {
        $type->remaining += $order->quantity;
        $type->save();
        $order->status = $status;
        $order->save();
        $this->log('order.'.$status, $order);
    }

    public function expire(Order $candidate): Order
    {
        // Avoid locking inventory when the order is already terminal or its deadline is still in the future.
        if ($candidate->status !== 'pending' || now()->lt($candidate->expires_at)) {
            return $candidate;
        }

        return DB::transaction(function () use ($candidate) {
            $type = TicketType::whereKey($candidate->ticket_type_id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'pending' && now()->gte($order->expires_at)) {
                $this->releaseLocked($order, $type, 'expired');
            }

            return $order;
        }, 5);
    }

    /** Only called by the explicitly enabled local mock gateway. No public payment callback exists. */
    public function confirmMock(Order $candidate): Order
    {
        abort_unless(app()->environment(['local', 'testing']) && config('ticketing.mock_payment'), 404);

        return DB::transaction(function () use ($candidate) {
            $type = TicketType::whereKey($candidate->ticket_type_id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending') {
                return $order;
            }
            if (now()->gte($order->expires_at)) {
                $this->releaseLocked($order, $type, 'expired');

                return $order;
            }
            $order->status = 'confirmed';
            $order->confirmed_at = now();
            $order->save();
            $this->issueTickets($order);
            $this->log('payment.mock_confirmed', $order);

            return $order;
        }, 5);
    }

    public function cancel(Order $candidate): Order
    {
        return DB::transaction(function () use ($candidate) {
            $type = TicketType::whereKey($candidate->ticket_type_id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (in_array($order->status, ['cancelled', 'expired'], true)) {
                return $order;
            }
            if ($order->status === 'confirmed' && $order->amount_cents > 0) {
                $this->fail('已付款訂單需由主辦單位辦理退款，本版不提供自動退款。');
            }
            if (now()->gte($type->event->starts_at)) {
                $this->fail('活動已開始，不能取消。');
            }
            if ($order->tickets()->whereNotNull('checked_in_at')->exists()) {
                $this->fail('票券已核銷，不能取消。');
            }
            $this->releaseLocked($order, $type, 'cancelled');

            return $order;
        }, 5);
    }

    public function checkIn(string $token, User $actor): Ticket
    {
        abort_unless($actor->is_admin, 403);
        $candidate = Ticket::where('token', $token)->firstOrFail();

        return DB::transaction(function () use ($candidate, $actor) {
            // Cancellation also locks order before reading tickets.
            $order = Order::whereKey($candidate->order_id)->lockForUpdate()->firstOrFail();
            $ticket = Ticket::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'confirmed') {
                $this->fail('訂單不是報名成功狀態，無法核銷。');
            }
            if ($ticket->checked_in_at) {
                $this->fail('此票券已核銷，請勿重複入場。');
            }
            $event = $order->ticketType->event;
            if (now()->lt($event->starts_at->copy()->subHours(2)) || now()->gt($event->ends_at)) {
                $this->fail('核銷時間為活動前兩小時至活動結束。');
            }
            $ticket->checked_in_at = now();
            $ticket->checked_in_by = $actor->id;
            $ticket->save();
            $this->log('ticket.checked_in', $order, $actor->id);

            return $ticket;
        }, 5);
    }
}
