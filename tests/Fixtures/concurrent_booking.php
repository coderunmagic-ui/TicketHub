<?php

use App\Models\TicketType;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_ends_with(config('database.connections.mariadb.database'), '_test')) {
    throw new RuntimeException('Concurrency fixture requires a dedicated *_test MariaDB database.');
}
[$script,$userId,$typeId,$requestKey,$barrier,$worker] = $argv;
file_put_contents($barrier.'.ready.'.$worker, 'ready');
$deadline = microtime(true) + 20;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timed out');
    }
    usleep(10000);
}
try {
    $order = app(BookingService::class)->book(User::findOrFail($userId), TicketType::findOrFail($typeId), [
        'request_key' => $requestKey, 'quantity' => 1, 'attendee_name' => 'Concurrent buyer', 'phone' => '0912345678', 'contact_email' => 'buyer@example.com',
    ]);
    echo json_encode(['result' => 'success', 'order' => $order->number]);
} catch (ValidationException $e) {
    echo json_encode(['result' => 'rejected', 'errors' => $e->errors()]);
}
