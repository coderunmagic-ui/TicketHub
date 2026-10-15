<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SeedLoadTest extends Command
{
    protected $signature = 'load:seed {--users=100} {--capacity=20}';

    protected $description = 'Create isolated load-test accounts and an event; refuses production databases';

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment(['local', 'testing']) || ! config('loadtest.enabled') || ! preg_match('/_(test|load)$/', $database)) {
            $this->error('Requires local/testing, ALLOW_LOAD_TEST_FIXTURE=true and a *_test or *_load database.');

            return self::FAILURE;
        }
        $password = config('loadtest.password');
        if (! is_string($password) || strlen($password) < 12) {
            $this->error('Set LOAD_TEST_PASSWORD to at least 12 characters.');

            return self::FAILURE;
        }
        $count = max(1, min(10000, (int) $this->option('users')));
        $capacity = max(1, min(100000, (int) $this->option('capacity')));
        $prefix = 'load_'.Str::lower(Str::random(8)).'_';
        $hash = Hash::make($password);
        $event = DB::transaction(function () use ($count, $capacity, $prefix, $hash) {
            $event = Event::create(['title' => 'Load test '.$prefix, 'description' => 'Isolated load test', 'location' => 'Test', 'published' => true, 'opens_at' => now()->subHour(), 'closes_at' => now()->addDay(), 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(3)]);
            $event->ticketTypes()->create(['name' => 'Load ticket', 'price_cents' => 0, 'capacity' => $capacity, 'remaining' => $capacity, 'per_user_limit' => 1]);
            $rows = [];
            for ($i = 1; $i <= $count; $i++) {
                $rows[] = ['name' => 'Load '.$i, 'email' => $prefix.$i.'@example.test', 'password' => $hash, 'email_verified_at' => now(), 'is_admin' => false, 'created_at' => now(), 'updated_at' => now()];
                if (count($rows) === 500) {
                    User::insert($rows);
                    $rows = [];
                }
            }
            if ($rows) {
                User::insert($rows);
            }

            return $event;
        });
        $this->line(json_encode(['event_id' => $event->id, 'ticket_type_id' => $event->ticketTypes()->firstOrFail()->id, 'user_prefix' => $prefix, 'users' => $count, 'capacity' => $capacity], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
