<?php

return [
    'hold_minutes' => (int) env('TICKET_HOLD_MINUTES', 10),
    'mock_payment' => (bool) env('MOCK_PAYMENT_ENABLED', false),
];
