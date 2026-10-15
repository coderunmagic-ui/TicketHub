<?php

return [
    'login_ip' => (int) env('LOGIN_IP_LIMIT', 30),
    'booking_ip' => (int) env('BOOKING_IP_LIMIT', 120),
    'poll_ip' => (int) env('WAITING_POLL_IP_LIMIT', 3000),
];
