<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('is_admin')->default(false));
        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description');
            $t->string('location');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->dateTime('opens_at');
            $t->dateTime('closes_at');
            $t->boolean('published')->default(false)->index();
            $t->timestamps();
        });
        Schema::create('ticket_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->unsignedInteger('price_cents')->default(0);
            $t->unsignedInteger('capacity');
            $t->unsignedInteger('remaining');
            $t->unsignedSmallInteger('per_user_limit')->default(1);
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('number')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('ticket_type_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->unsignedSmallInteger('quantity');
            $t->unsignedBigInteger('amount_cents');
            $t->char('currency', 3)->default('TWD');
            $t->string('status', 24)->index();
            $t->string('attendee_name');
            $t->string('phone', 40);
            $t->string('contact_email');
            $t->dateTime('expires_at')->nullable();
            $t->dateTime('confirmed_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
            $t->index(['status', 'expires_at']);
            $t->index(['user_id', 'ticket_type_id', 'status']);
        });
        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->string('token', 64)->unique();
            $t->dateTime('checked_in_at')->nullable();
            $t->foreignId('checked_in_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 80);
            $t->json('context')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'tickets', 'orders', 'ticket_types', 'events'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
    }
};
