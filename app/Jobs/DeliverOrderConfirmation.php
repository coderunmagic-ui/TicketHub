<?php

namespace App\Jobs;

use App\Mail\OrderConfirmedMail;
use App\Models\NotificationOutbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class DeliverOrderConfirmation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $outboxId) {}

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(): void
    {
        Cache::store('redis')->lock('outbox:delivery:'.$this->outboxId, 60)->block(1, function (): void {
            $row = NotificationOutbox::findOrFail($this->outboxId);
            if ($row->sent_at) {
                return;
            }
            $order = $row->order()->with('ticketType.event')->firstOrFail();
            if ($order->status === 'confirmed') {
                Mail::to($order->contact_email)->send(new OrderConfirmedMail($order));
            }
            // SMTP has no universal exactly-once primitive. A crash here may resend an email, never another ticket.
            $row->update(['sent_at' => now(), 'last_error' => null]);
        });
    }

    public function failed(?\Throwable $error): void
    {
        NotificationOutbox::whereKey($this->outboxId)->whereNull('sent_at')->update(['leased_until' => now()->addMinutes(10), 'last_error' => 'delivery_failed_check_worker_log']);
    }
}
