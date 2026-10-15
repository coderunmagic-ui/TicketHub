<?php

namespace App\Services;

use App\Contracts\CheckoutGateway;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;

class MockCheckoutGateway implements CheckoutGateway
{
    public function __construct(private BookingService $bookings) {}

    public function checkout(Order $order): RedirectResponse
    {
        $result = $this->bookings->confirmMock($order);

        return redirect()->route('orders.show', $order)->with('status', $result->status === 'confirmed' ? '測試付款完成，未收取真實款項。' : '訂單已逾期或取消，未付款。');
    }
}
