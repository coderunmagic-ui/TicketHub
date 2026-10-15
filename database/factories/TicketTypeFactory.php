<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketTypeFactory extends Factory
{
    public function definition(): array
    {
        return ['event_id' => Event::factory(), 'name' => '一般票', 'price_cents' => 0, 'capacity' => 5, 'remaining' => 5, 'per_user_limit' => 2];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['price_cents' => 50000]);
    }
}
