<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderConfirmedMail extends Mailable
{
    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '報名成功：'.$this->order->ticketType->event->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-confirmed');
    }
}
