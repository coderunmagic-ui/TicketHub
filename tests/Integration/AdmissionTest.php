<?php

namespace Tests\Integration;

use App\Jobs\DeliverOrderConfirmation;
use App\Mail\OrderConfirmedMail;
use App\Models\NotificationOutbox;
use App\Models\TicketType;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\BookingService;
use App\Services\EventCatalog;
use App\Services\OutboxPublisher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admission.enabled' => true, 'admission.catalog_cache' => false, 'admission.namespace' => 'test-'.Str::uuid(), 'admission.slots' => 1, 'admission.rate' => 100, 'admission.burst' => 100]);
        Redis::connection('admission')->ping();
    }

    protected function tearDown(): void
    {
        try {
            Redis::connection('admission')->del(...app(AdmissionService::class)->keys());
        } catch (\Throwable) {
        } finally {
            parent::tearDown();
        }
    }

    private function input(array $extra = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'quantity' => 1, 'attendee_name' => '會員', 'phone' => '0912345678', 'contact_email' => 'member@example.com'], $extra);
    }

    public function test_fifo_global_slots_and_release_promote_next_member(): void
    {
        $a = app(AdmissionService::class);
        $first = $a->join(1, 1);
        $second = $a->join(2, 1);
        $third = $a->join(3, 2);
        $this->assertSame('admitted', $first['state']);
        $this->assertSame(1, $second['position']);
        $this->assertSame(2, $third['position']);
        $lease = $a->claim($first['queue_token'], 1, 1);
        $a->release($lease);
        $this->assertSame('admitted', $a->status($second['queue_token'])['state']);
        $this->assertSame('waiting', $a->status($third['queue_token'])['state']);
    }

    public function test_member_cannot_hold_two_places_or_change_ticket_type(): void
    {
        $a = app(AdmissionService::class);
        $first = $a->join(1, 1);
        $this->assertSame('admitted', $a->join(1, 1)['state']);
        $this->assertSame(1, $a->metrics()['active']);
        $this->assertSame('conflict', $a->join(1, 2)['state']);
        $this->assertSame('left', $a->leave($first['queue_token'])['state']);
        $this->assertSame('admitted', $a->join(1, 2)['state']);
    }

    public function test_claim_is_single_use_and_bound_to_member_and_ticket(): void
    {
        $a = app(AdmissionService::class);
        $first = $a->join(1, 1);
        foreach ([[2, 1], [1, 2]] as [$user,$type]) {
            try {
                $a->claim($first['queue_token'], $user, $type);
                $this->fail('Mismatched credential accepted');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $a->claim($first['queue_token'], 1, 1);
        try {
            $a->claim($first['queue_token'], 1, 1);
            $this->fail('Duplicate claim accepted');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
        $this->assertSame('processing', $a->leave($first['queue_token'])['state']);
    }

    public function test_expired_permit_rejects_old_token_and_does_not_release_new_session(): void
    {
        $a = app(AdmissionService::class);
        $first = $a->join(1, 1);
        $lease = $a->claim($first['queue_token'], 1, 1);
        Redis::connection('admission')->zadd($a->keys()[2], 0, '1');
        $replacement = $a->join(1, 1);
        $a->release($lease);
        $this->assertSame('gone', $a->status($first['queue_token'])['state']);
        $this->assertSame('admitted', $a->status($replacement['queue_token'])['state']);
    }

    public function test_waiting_heartbeat_expiry_removes_abandoned_members(): void
    {
        $a = app(AdmissionService::class);
        $a->join(1, 1);
        $waiting = $a->join(2, 1);
        Redis::connection('admission')->zadd($a->keys()[1], 0, '2');
        $this->assertSame('gone', $a->status($waiting['queue_token'])['state']);
        $this->assertSame(0, $a->metrics()['waiting']);
    }

    public function test_token_bucket_limits_admissions_even_with_free_slots(): void
    {
        config(['admission.slots' => 10, 'admission.rate' => 1, 'admission.burst' => 1]);
        $a = app(AdmissionService::class);
        $first = $a->join(1, 1);
        $second = $a->join(2, 1);
        $this->assertSame('admitted', $first['state']);
        $this->assertSame('waiting', $second['state']);
        $this->assertSame(1, $a->metrics()['active']);
    }

    public function test_maximum_queue_size_is_enforced(): void
    {
        config(['admission.max_waiters' => 2]);
        $a = app(AdmissionService::class);
        $a->join(1, 1);
        $a->join(2, 1);
        $this->assertSame('full', $a->join(3, 1)['state']);
    }

    public function test_status_is_stateless_and_executes_zero_sql_queries(): void
    {
        $token = app(AdmissionService::class)->join(123, 456)['queue_token'];
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson(route('waiting.status'))->assertOk()->assertJsonPath('state', 'admitted');
        $this->assertSame([], $queries);
    }

    public function test_booking_endpoint_rejects_bypass_and_accepts_valid_permit(): void
    {
        $type = TicketType::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('orders.store', $type), $this->input())->assertStatus(429);
        $this->assertDatabaseCount('orders', 0);
        $joined = $this->postJson(route('waiting.join', $type))->assertOk()->assertJsonPath('state', 'admitted')->json();
        $this->post(route('orders.store', $type), $this->input(['queue_token' => $joined['queue_token']]))->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('notification_outbox', 1);
        $this->assertSame(0, app(AdmissionService::class)->metrics()['active']);
    }

    public function test_waiting_page_and_event_link_render_when_admission_enabled(): void
    {
        $type = TicketType::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('events.show', $type->event))->assertOk()->assertSee('進入候位室');
        $this->get(route('waiting.show', $type))->assertOk()->assertSee('先候位，再安心報名。');
    }

    public function test_invalid_token_is_rejected_without_sql(): void
    {
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $this->withHeader('Authorization', 'Bearer forged')->postJson(route('waiting.status'))->assertForbidden();
        $this->assertSame([], $queries);
    }

    public function test_redis_failure_closes_gate_before_any_inventory_is_changed(): void
    {
        $type = TicketType::factory()->create();
        $user = User::factory()->create();
        $token = app(AdmissionService::class)->join($user->id, $type->id)['queue_token'];
        Redis::partialMock()->shouldReceive('connection')->with('admission')->andThrow(new \RuntimeException('simulated Redis outage'));
        $this->actingAs($user)->postJson(route('orders.store', $type), $this->input(['queue_token' => $token]))->assertStatus(503);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $type->fresh()->remaining);
        Redis::clearResolvedInstances();
        $this->app->forgetInstance('redis');
    }

    public function test_confirmation_outbox_is_committed_once_and_queue_failure_is_retryable(): void
    {
        $type = TicketType::factory()->create();
        $user = User::factory()->create();
        $payload = $this->input();
        $service = app(BookingService::class);
        $order = $service->book($user, $type, $payload);
        $service->book($user, $type, $payload);
        $this->assertDatabaseCount('notification_outbox', 1);
        Queue::fake();
        $publisher = app(OutboxPublisher::class);
        $this->assertSame(1, $publisher->publish());
        $this->assertSame(0, $publisher->publish());
        Queue::assertPushed(DeliverOrderConfirmation::class, 1);
        $row = NotificationOutbox::firstOrFail();
        $row->update(['leased_until' => now()->subMinute()]);
        $this->assertSame(1, $publisher->publish());
        Queue::assertPushed(DeliverOrderConfirmation::class, 2);
        $this->assertSame(1, $order->tickets()->count());
    }

    public function test_repeated_notification_job_sends_once_and_does_not_reissue_tickets(): void
    {
        $order = app(BookingService::class)->book(User::factory()->create(), TicketType::factory()->create(), $this->input());
        Mail::fake();
        $row = NotificationOutbox::firstOrFail();
        $job = new DeliverOrderConfirmation($row->id);
        $job->handle();
        $job->handle();
        Mail::assertSent(OrderConfirmedMail::class, 1);
        $this->assertNotNull($row->fresh()->sent_at);
        $this->assertSame(1, $order->tickets()->count());
    }

    public function test_queue_dispatch_failure_preserves_outbox_for_retry(): void
    {
        app(BookingService::class)->book(User::factory()->create(), TicketType::factory()->create(), $this->input());
        Queue::partialMock()->shouldReceive('connection')->with('redis')->andThrow(new \RuntimeException('simulated queue outage'));
        $this->assertSame(0, app(OutboxPublisher::class)->publish());
        $row = NotificationOutbox::firstOrFail();
        $this->assertNull($row->sent_at);
        $this->assertSame('queue_dispatch_failed', $row->last_error);
        $this->assertNotNull($row->leased_until);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_order_and_outbox_rollback_together(): void
    {
        $type = TicketType::factory()->create();
        $user = User::factory()->create();
        try {
            DB::transaction(function () use ($type, $user) {
                app(BookingService::class)->book($user, $type, $this->input());
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('notification_outbox', 0);
        $this->assertDatabaseCount('tickets', 0);
        $this->assertSame(5, $type->fresh()->remaining);
    }

    public function test_public_event_cache_avoids_sql_and_admin_invalidation_refreshes_it(): void
    {
        config(['admission.catalog_cache' => true]);
        $type = TicketType::factory()->create();
        $catalog = app(EventCatalog::class);
        $catalog->invalidate($type->event_id);
        $catalog->event($type->event_id);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->get(route('events.show', $type->event_id))->assertOk();
        $this->assertSame([], $queries);
        $expectedTitle = $type->event->title;
        $catalog->listing(1);
        $queries = [];
        $this->get('/')->assertOk()->assertSee($expectedTitle);
        $this->assertSame([], $queries);
        $type->event->update(['published' => false]);
        $catalog->invalidate($type->event_id);
        $this->get(route('events.show', $type->event_id))->assertNotFound();
        $catalog->invalidate($type->event_id);
    }

    public function test_load_fixture_is_opt_in_and_inventory_reconciliation_detects_corruption(): void
    {
        config(['loadtest.enabled' => false]);
        $this->artisan('load:seed')->assertFailed();
        $this->assertDatabaseCount('users', 0);
        config(['loadtest.enabled' => true, 'loadtest.password' => 'Only-a-test-password-123']);
        $this->artisan('load:seed', ['--users' => 3, '--capacity' => 2])->assertSuccessful();
        $this->assertDatabaseCount('users', 3);
        $this->assertSame(0, User::where('is_admin', true)->count());
        $this->assertSame(3, User::whereNotNull('email_verified_at')->count());
        $type = TicketType::firstOrFail();
        $this->artisan('load:check', ['event' => $type->event_id])->assertSuccessful();
        $type->update(['remaining' => 0]);
        $this->artisan('load:check', ['event' => $type->event_id])->assertFailed();
    }

    public function test_real_redis_worker_delivers_committed_outbox_job(): void
    {
        app(BookingService::class)->book(User::factory()->create(), TicketType::factory()->create(), $this->input());
        Mail::fake();
        $this->assertSame(1, app(OutboxPublisher::class)->publish());
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'notifications', '--stop-when-empty' => true, '--tries' => 1, '--max-time' => 15])->assertSuccessful();
        Mail::assertSent(OrderConfirmedMail::class, 1);
        $this->assertNotNull(NotificationOutbox::firstOrFail()->sent_at);
    }
}
