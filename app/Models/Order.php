<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'quantity' => 'integer', 'amount_cents' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function ticketType()
    {
        return $this->belongsTo(TicketType::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function statusLabel(): string
    {
        return ['pending' => '待付款', 'confirmed' => '報名成功', 'expired' => '已逾期', 'cancelled' => '已取消'][$this->status] ?? $this->status;
    }
}
