<?php

namespace Tests\Concurrency;

use App\Services\AdmissionService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdmissionConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! env('RUN_REDIS_TESTS')) {
            $this->markTestSkipped('Set RUN_REDIS_TESTS=true with a dedicated Redis instance.');
        }
        config(['admission.namespace' => 'test-'.Str::uuid(), 'admission.slots' => 5, 'admission.rate' => 100, 'admission.burst' => 100]);
    }

    protected function tearDown(): void
    {
        if (str_starts_with(config('admission.namespace', ''), 'test-')) {
            Redis::connection('admission')->del(...app(AdmissionService::class)->keys());
        }
        parent::tearDown();
    }

    private function race(array $members, string $mode = 'join', string $token = ''): array
    {
        $barrier = sys_get_temp_dir().'/admission-'.Str::uuid();
        $processes = [];
        try {
            foreach ($members as $i => $member) {
                $p = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent_admission.php'), (string) $member, $barrier, (string) $i, $mode, $token], base_path(), [
                    'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'ADMISSION_NAMESPACE' => config('admission.namespace'), 'ADMISSION_SLOTS' => '5', 'ADMISSION_RATE' => '100', 'ADMISSION_BURST' => '100',
                    'REDIS_HOST' => config('database.redis.admission.host'), 'REDIS_PORT' => (string) config('database.redis.admission.port'),
                    'REDIS_ADMISSION_PORT' => (string) config('database.redis.admission.port'), 'REDIS_ADMISSION_DB' => (string) config('database.redis.admission.database'),
                ]);
                $p->setTimeout(30);
                $p->start();
                $processes[] = $p;
            }
            $end = microtime(true) + 15;
            while (count(glob($barrier.'.ready.*')) < count($members) && microtime(true) < $end) {
                usleep(10000);
            }
            $this->assertCount(count($members), glob($barrier.'.ready.*'));
            file_put_contents($barrier, 'go');
            $results = [];
            foreach ($processes as $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput().$p->getOutput());
                $results[] = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }foreach (glob($barrier.'*') as $f) {
                unlink($f);
            }
        }
    }

    public function test_thirty_two_simultaneous_arrivals_never_exceed_five_permits(): void
    {
        $results = $this->race(range(1, 32));
        $this->assertCount(5, array_filter($results, fn ($r) => $r['state'] === 'admitted'));
        $m = app(AdmissionService::class)->metrics();
        $this->assertSame(5, $m['active']);
        $this->assertSame(27, $m['waiting']);
        $this->assertSame(5, $m['peak_active']);
    }

    public function test_sixteen_simultaneous_claims_only_allow_one_request(): void
    {
        $token = app(AdmissionService::class)->join(1, 1)['queue_token'];
        $results = $this->race(array_fill(0, 16, 1), 'claim', $token);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['state'] === 'claimed'));
        $this->assertCount(15, array_filter($results, fn ($r) => $r['state'] === 'denied' && $r['status'] === 429));
    }
}
