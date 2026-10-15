<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'opens_at' => 'datetime', 'closes_at' => 'datetime', 'published' => 'boolean'];
    }

    public function ticketTypes()
    {
        return $this->hasMany(TicketType::class);
    }
}
