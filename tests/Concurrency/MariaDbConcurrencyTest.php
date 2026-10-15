<?php

namespace Tests\Concurrency;

use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mariadb' || ! str_ends_with(config('database.connections.mariadb.database'), '_test')) {
            $this->markTestSkipped('Requires dedicated MariaDB *_test database.');
        }
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    private function runBuyers(TicketType $type, array $users, bool $sameKey = false): array
    {
        $db = config('database.connections.mariadb');
        $barrier = sys_get_temp_dir().'/tickethub-barrier-'.Str::uuid();
        $key = (string) Str::uuid();
        $processes = [];
        try {
            foreach ($users as $i => $user) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent_booking.php'), (string) $user->id, (string) $type->id, $sameKey ? $key : (string) Str::uuid(), $barrier, (string) $i], base_path(), [
                    'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mariadb', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'],
                    'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'DB_SOCKET' => $db['unix_socket'] ?? '',
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
                ]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            do {
                $ready = count(glob($barrier.'.ready.*')) === count($processes);
                if (! $ready) {
                    usleep(10000);
                }
            } while (! $ready && microtime(true) < $deadline);
            $this->assertTrue($ready, 'All workers must be ready before releasing the start barrier.');
            file_put_contents($barrier, 'start');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($barrier.'*') as $file) {
                unlink($file);
            }
        }
    }

    public function test_twelve_concurrent_buyers_can_only_claim_three_tickets(): void
    {
        $type = TicketType::factory()->create(['capacity' => 3, 'remaining' => 3]);
        $results = $this->runBuyers($type, User::factory()->count(12)->create()->all());
        $this->assertCount(3, array_filter($results, fn ($r) => $r['result'] === 'success'));
        $this->assertDatabaseCount('orders', 3);
        $this->assertDatabaseCount('tickets', 3);
        $this->assertSame(0, $type->fresh()->remaining);
    }

    public function test_simultaneous_retries_for_one_user_only_create_one_order(): void
    {
        $type = TicketType::factory()->create();
        $user = User::factory()->create();
        $results = $this->runBuyers($type, array_fill(0, 8, $user), true);
        $this->assertCount(8, array_filter($results, fn ($r) => $r['result'] === 'success'));
        $this->assertCount(1, array_unique(array_column($results, 'order')));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame(4, $type->fresh()->remaining);
    }

    public function test_simultaneous_distinct_requests_cannot_bypass_member_limit(): void
    {
        $type = TicketType::factory()->create(['capacity' => 20, 'remaining' => 20, 'per_user_limit' => 2]);
        $user = User::factory()->create();
        $results = $this->runBuyers($type, array_fill(0, 8, $user));
        $this->assertCount(2, array_filter($results, fn ($r) => $r['result'] === 'success'));
        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(18, $type->fresh()->remaining);
    }
}
