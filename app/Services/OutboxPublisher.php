<?php

namespace App\Services;

use App\Jobs\DeliverOrderConfirmation;
use App\Models\NotificationOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutboxPublisher
{
    public function publish(int $limit = 100): int
    {
        $token = (string) Str::uuid();
        $ids = DB::transaction(function () use ($limit, $token) {
            $rows = NotificationOutbox::whereNull('sent_at')->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<=', now()))->orderBy('id')->limit(max(1, min(1000, $limit)))->lockForUpdate()->get();
            foreach ($rows as $row) {
                $row->update(['dispatch_token' => $token, 'leased_until' => now()->addMinutes(5)]);
            }

            return $rows->modelKeys();
        }, 3);
        $published = 0;
        foreach ($ids as $id) {
            try {
                DeliverOrderConfirmation::dispatch($id)->onConnection('redis')->onQueue('notifications');
                $published++;
            } catch (\Throwable $error) {
                NotificationOutbox::whereKey($id)->where('dispatch_token', $token)->whereNull('sent_at')->update(['leased_until' => now()->addSeconds(15), 'last_error' => 'queue_dispatch_failed']);
                report($error);
            }
        }

        return $published;
    }
}
