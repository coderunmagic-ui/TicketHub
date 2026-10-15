<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => '測試活動', 'description' => '活動介紹', 'location' => '台北', 'published' => true,
            'opens_at' => now()->subHour(), 'closes_at' => now()->addDay(), 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(4)];
    }
}
