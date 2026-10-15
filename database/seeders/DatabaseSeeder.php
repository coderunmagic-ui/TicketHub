<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (Event::exists()) {
            return;
        }
        $event = Event::create(['title' => '秋日創作者交流日', 'description' => "一起分享作品、交流經驗，認識新的夥伴。\n此為示範活動，包含免費入場與付費工作坊。", 'location' => '台北・創意交流空間', 'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(13), 'starts_at' => now()->addDays(14)->setTime(10, 0), 'ends_at' => now()->addDays(14)->setTime(18, 0), 'published' => true]);
        $event->ticketTypes()->createMany([
            ['name' => '免費入場券', 'price_cents' => 0, 'capacity' => 100, 'remaining' => 100, 'per_user_limit' => 1],
            ['name' => '工作坊套票', 'price_cents' => 50000, 'capacity' => 30, 'remaining' => 30, 'per_user_limit' => 2],
        ]);
    }
}
