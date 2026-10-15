<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountAndAdminTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registration_hashes_password_sends_verification_and_cannot_grant_admin(): void
    {
        Notification::fake();
        $this->post(route('register'), ['name' => '會員', 'email' => 'new@example.com', 'password' => 'A-long-password-123', 'password_confirmation' => 'A-long-password-123', 'is_admin' => true])->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertFalse($user->is_admin);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('A-long-password-123', $user->password));
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_signed_email_verification_enables_account(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($url)->assertRedirect('/orders');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_admin_can_create_event_add_ticket_type_and_unpublish(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['is_admin' => true]);
        $data = ['title' => '新活動', 'description' => '介紹', 'location' => '台北', 'opens_at' => now()->subHour()->format('Y-m-d H:i:s'), 'closes_at' => now()->addDay()->format('Y-m-d H:i:s'), 'starts_at' => now()->addDays(2)->format('Y-m-d H:i:s'), 'ends_at' => now()->addDays(2)->addHours(4)->format('Y-m-d H:i:s'), 'published' => 1];
        $this->actingAs($admin)->post(route('admin.events.store'), $data)->assertRedirect();
        $event = Event::firstOrFail();
        $this->post(route('admin.types.store', $event), ['name' => '套票', 'price' => 500, 'capacity' => 20, 'per_user_limit' => 2])->assertRedirect();
        $this->assertDatabaseHas('ticket_types', ['event_id' => $event->id, 'price_cents' => 50000, 'remaining' => 20]);
        $this->put(route('admin.events.update', $event), array_replace($data, ['published' => 0]))->assertRedirect();
        $this->get(route('events.show', $event))->assertNotFound();
    }

    public function test_login_rejects_wrong_password_and_accepts_correct_password(): void
    {
        $user = User::factory()->create(['password' => 'A-long-password-123']);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'A-long-password-123'])->assertRedirect('/orders');
        $this->assertAuthenticatedAs($user);
    }

    public function test_mock_payment_is_disabled_by_default(): void
    {
        config(['ticketing.mock_payment' => false]);
        $user = User::factory()->create();
        $type = TicketType::factory()->paid()->create();
        $order = app(BookingService::class)->book($user, $type, ['request_key' => (string) Str::uuid(), 'quantity' => 1, 'attendee_name' => '會員', 'contact_email' => 'member@example.com', 'phone' => '0912345678']);
        $this->actingAs($user)->post(route('orders.pay', $order))->assertNotFound();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }
}
