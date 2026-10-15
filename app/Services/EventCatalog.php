<?php

namespace App\Services;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class EventCatalog
{
    public function listing(int $page): LengthAwarePaginator
    {
        $read = function () use ($page): array {
            $results = Event::where('published', true)->with('ticketTypes')->orderBy('starts_at')->paginate(12, ['*'], 'page', $page);

            return ['items' => $results->getCollection()->map(fn (Event $event): array => $this->snapshot($event))->all(), 'total' => $results->total()];
        };
        if (config('admission.catalog_cache')) {
            $version = Cache::store('redis')->get('catalog:version', 0);
            $data = $this->remember('catalog:data:list:'.$version.':'.$page, $read);
        } else {
            $data = $read();
        }

        return new LengthAwarePaginator(array_map(fn (array $item): Event => $this->hydrate($item), $data['items']), $data['total'], 12, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
    }

    public function event(int $id): Event
    {
        $read = fn (): array => $this->snapshot(Event::with('ticketTypes')->findOrFail($id));
        $data = config('admission.catalog_cache') ? $this->remember('catalog:data:event:'.$id, $read) : $read();

        return $this->hydrate($data);
    }

    private function snapshot(Event $event): array
    {
        return ['attributes' => $event->getAttributes(), 'ticket_types' => $event->ticketTypes->map(fn (TicketType $type): array => $type->getAttributes())->all()];
    }

    private function hydrate(array $snapshot): Event
    {
        $event = (new Event)->newFromBuilder($snapshot['attributes']);
        $types = array_map(fn (array $attributes): TicketType => (new TicketType)->newFromBuilder($attributes), $snapshot['ticket_types']);
        $event->setRelation('ticketTypes', (new TicketType)->newCollection($types));

        return $event;
    }

    private function remember(string $key, \Closure $read): array
    {
        $cache = Cache::store('redis');
        $value = $cache->get($key);
        if (is_array($value)) {
            return $value;
        }

        return $cache->lock($key.':fill', 10)->block(2, function () use ($cache, $key, $read): array {
            $value = $cache->get($key);
            if (is_array($value)) {
                return $value;
            }
            $value = $read();
            $cache->put($key, $value, max(1, config('admission.catalog_seconds')));

            return $value;
        });
    }

    public function invalidate(int $id): void
    {
        if (! config('admission.catalog_cache')) {
            return;
        }
        try {
            Cache::store('redis')->forget('catalog:data:event:'.$id);
            Cache::store('redis')->increment('catalog:version');
        } catch (\Throwable $error) {
            report($error);
        }
    }
}
