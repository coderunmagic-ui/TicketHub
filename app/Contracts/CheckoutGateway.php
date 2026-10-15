<?php

namespace App\Contracts;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;

/** A real gateway redirects to its hosted checkout; a verified server callback must settle payment separately. */
interface CheckoutGateway
{
    public function checkout(Order $order): RedirectResponse;
}
