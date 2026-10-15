<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Console\Command;

class CheckLoadTest extends Command
{
    protected $signature = 'load:check {event}';

    protected $description = 'Read-only inventory and issued-ticket reconciliation for an event';

    public function handle(): int
    {
        $event = Event::findOrFail((int) $this->argument('event'));
        $ok = true;
        foreach ($event->ticketTypes as $type) {
            $committed = (int) $type->orders()->whereIn('status', ['pending', 'confirmed'])->sum('quantity');
            $confirmed = (int) $type->orders()->where('status', 'confirmed')->sum('quantity');
            $tickets = Ticket::whereHas('order', fn ($q) => $q->where('ticket_type_id', $type->id)->where('status', 'confirmed'))->count();
            $valid = $type->capacity === $type->remaining + $committed && $confirmed === $tickets;
            $ok = $ok && $valid;
            $this->line(json_encode(['ticket_type' => $type->id, 'capacity' => $type->capacity, 'remaining' => $type->remaining, 'held_or_confirmed' => $committed, 'confirmed_quantity' => $confirmed, 'valid_tickets' => $tickets, 'consistent' => $valid]));
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
