<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_replace(['request_key' => (string) Str::uuid(), 'quantity' => 1, 'attendee_name' => '測試會員', 'phone' => '0912345678', 'contact_email' => 'guest@example.com'], $overrides);
    }

    private function booked(User $user, TicketType $type, array $input = []): Order
    {
        return app(BookingService::class)->book($user, $type, $this->payload($input));
    }

    public function test_free_registration_issues_tickets_and_ignores_client_price(): void
    {
        $type = TicketType::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('orders.store', $type), $this->payload(['quantity' => 2, 'amount_cents' => 1, 'status' => 'pending']))->assertRedirect();
        $this->assertDatabaseHas('orders', ['user_id' => $user->id, 'status' => 'confirmed', 'amount_cents' => 0, 'quantity' => 2]);
        $this->assertDatabaseCount('tickets', 2);
        $this->assertSame(3, $type->fresh()->remaining);
    }

    public function test_duplicate_request_returns_the_same_order_without_decrementing_twice(): void
    {
        $type = TicketType::factory()->create();
        $user = User::factory()->create();
        $input = $this->payload();
        $first = app(BookingService::class)->book($user, $type, $input);
        $this->actingAs($user)->post(route('orders.store', $type), $input)->assertRedirect(route('orders.show', $first));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(4, $type->fresh()->remaining);
    }

    public function test_reused_key_with_changed_payload_is_rejected(): void
    {
        $type = TicketType::factory()->create();
        $user = User::factory()->create();
        $input = $this->payload();
        app(BookingService::class)->book($user, $type, $input);
        $this->actingAs($user)->post(route('orders.store', $type), array_replace($input, ['quantity' => 2]))->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(4, $type->fresh()->remaining);
    }

    public function test_sold_out_inventory_rejects_a_second_buyer(): void
    {
        $type = TicketType::factory()->create(['capacity' => 1, 'remaining' => 1]);
        $this->booked(User::factory()->create(), $type);
        $this->actingAs(User::factory()->create())->post(route('orders.store', $type), $this->payload())->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(0, $type->fresh()->remaining);
    }

    public function test_per_user_limit_counts_previous_orders(): void
    {
        $type = TicketType::factory()->create(['per_user_limit' => 1]);
        $user = User::factory()->create();
        $this->booked($user, $type);
        $this->actingAs($user)->post(route('orders.store', $type), $this->payload())->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_booking_requires_login_and_verified_email(): void
    {
        $type = TicketType::factory()->create();
        $this->post(route('orders.store', $type), $this->payload())->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())->post(route('orders.store', $type), $this->payload())->assertRedirect(route('verification.notice'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_booking_validates_quantity_and_opening_time(): void
    {
        $this->freezeTime();
        $type = TicketType::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('orders.store', $type), $this->payload(['quantity' => 0]))->assertSessionHasErrors('quantity');
        $type->event->update(['opens_at' => now()->addHour()]);
        $this->post(route('orders.store', $type), $this->payload())->assertSessionHasErrors('booking');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $type->fresh()->remaining);
    }

    public function test_paid_order_is_pending_until_mock_payment_and_replay_is_idempotent(): void
    {
        config(['ticketing.mock_payment' => true]);
        $type = TicketType::factory()->paid()->create();
        $user = User::factory()->create();
        $order = $this->booked($user, $type);
        $this->assertSame('pending', $order->status);
        $this->assertDatabaseCount('tickets', 0);
        $this->actingAs($user)->post(route('orders.pay', $order))->assertRedirect(route('orders.show', $order));
        $this->post(route('orders.pay', $order))->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'confirmed', 'amount_cents' => 50000]);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_mock_payment_is_blocked_in_production_even_if_flag_is_enabled(): void
    {
        config(['ticketing.mock_payment' => true]);
        $type = TicketType::factory()->paid()->create();
        $user = User::factory()->create();
        $order = $this->booked($user, $type);
        $this->app->instance('env', 'production');
        // RequestForgeryProtection is excluded here so this test targets the payment environment guard.
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->actingAs($user)->post(route('orders.pay', $order))->assertNotFound();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_expiration_releases_once_and_cannot_issue_late_tickets(): void
    {
        $this->freezeTime();
        config(['ticketing.mock_payment' => true]);
        $type = TicketType::factory()->paid()->create();
        $user = User::factory()->create();
        $order = $this->booked($user, $type);
        $this->travel(11)->minutes();
        $this->artisan('orders:expire')->assertSuccessful();
        $this->artisan('orders:expire')->assertSuccessful();
        $this->actingAs($user)->post(route('orders.pay', $order))->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'expired']);
        $this->assertSame(5, $type->fresh()->remaining);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_payment_exactly_at_expiry_releases_and_rejects(): void
    {
        $this->freezeTime();
        config(['ticketing.mock_payment' => true]);
        $type = TicketType::factory()->paid()->create();
        $user = User::factory()->create();
        $order = $this->booked($user, $type);
        $this->travelTo($order->expires_at);
        $this->actingAs($user)->post(route('orders.pay', $order))->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'expired']);
        $this->assertSame(5, $type->fresh()->remaining);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_cancellation_releases_once_and_invalidates_free_ticket(): void
    {
        $type = TicketType::factory()->create();
        $user = User::factory()->create();
        $order = $this->booked($user, $type);
        $ticket = $order->tickets()->firstOrFail();
        $this->actingAs($user)->post(route('orders.cancel', $order))->assertRedirect();
        $this->post(route('orders.cancel', $order))->assertRedirect();
        $this->get(route('tickets.qr', $ticket))->assertForbidden();
        $this->assertSame(5, $type->fresh()->remaining);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
    }

    public function test_other_user_cannot_read_cancel_pay_or_download_ticket(): void
    {
        $type = TicketType::factory()->create();
        $owner = User::factory()->create();
        $order = $this->booked($owner, $type);
        $ticket = $order->tickets()->firstOrFail();
        $this->actingAs(User::factory()->create())->get(route('orders.show', $order))->assertForbidden();
        $this->post(route('orders.cancel', $order))->assertForbidden();
        $this->post(route('orders.pay', $order))->assertForbidden();
        $this->get(route('tickets.qr', $ticket))->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'confirmed']);
    }

    public function test_paid_confirmed_order_cannot_be_cancelled_without_refund(): void
    {
        config(['ticketing.mock_payment' => true]);
        $type = TicketType::factory()->paid()->create();
        $user = User::factory()->create();
        $order = $this->booked($user, $type);
        app(BookingService::class)->confirmMock($order);
        $this->actingAs($user)->post(route('orders.cancel', $order))->assertSessionHasErrors('booking');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'confirmed']);
        $this->assertSame(4, $type->fresh()->remaining);
    }

    public function test_admin_checkin_is_one_time_and_get_does_not_redeem(): void
    {
        $this->freezeTime();
        $event = Event::factory()->create(['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(4)]);
        $type = TicketType::factory()->for($event)->create();
        $order = $this->booked(User::factory()->create(), $type);
        $ticket = $order->tickets()->firstOrFail();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.scan', ['token' => $ticket->token]))->assertOk();
        $this->assertNull($ticket->fresh()->checked_in_at);
        $this->post(route('admin.checkin'), ['token' => $ticket->token])->assertRedirect();
        $this->assertNotNull($ticket->fresh()->checked_in_at);
        $this->post(route('admin.checkin'), ['token' => $ticket->token])->assertSessionHasErrors('booking');
    }

    public function test_regular_member_cannot_access_admin_or_change_capacity(): void
    {
        $type = TicketType::factory()->create();
        $this->actingAs(User::factory()->create())->get(route('admin.index'))->assertForbidden();
        $this->put(route('admin.types.capacity', $type), ['capacity' => 100])->assertForbidden();
        $this->assertSame(5, $type->fresh()->capacity);
    }

    public function test_capacity_cannot_drop_below_sold_and_held_tickets(): void
    {
        $type = TicketType::factory()->create();
        $this->booked(User::factory()->create(), $type, ['quantity' => 2]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->put(route('admin.types.capacity', $type), ['capacity' => 1])->assertSessionHasErrors('capacity');
        $this->assertSame(5, $type->fresh()->capacity);
        $this->put(route('admin.types.capacity', $type), ['capacity' => 10])->assertRedirect();
        $this->assertSame(8, $type->fresh()->remaining);
    }

    public function test_member_and_admin_views_and_qr_render(): void
    {
        $type = TicketType::factory()->create();
        $user = User::factory()->create(['is_admin' => true]);
        $order = $this->booked($user, $type);
        $ticket = $order->tickets()->firstOrFail();
        foreach (['/', route('events.show', $type->event), route('orders.index'), route('orders.show', $order), route('admin.index'), route('admin.events.create'), route('admin.events.edit', $type->event), route('admin.orders'), route('admin.scan')] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
        $this->get(route('tickets.qr', $ticket))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_event_html_is_escaped_and_csv_formulas_are_neutralized(): void
    {
        $type = TicketType::factory()->create();
        $type->event->update(['title' => '<script>alert(1)</script>']);
        $this->get(route('events.show', $type->event))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->booked($admin, $type, ['attendee_name' => '=1+1']);
        $response = $this->actingAs($admin)->get(route('admin.export'));
        $response->assertOk();
        $this->assertStringContainsString("'=1+1", $response->streamedContent());
    }
}
