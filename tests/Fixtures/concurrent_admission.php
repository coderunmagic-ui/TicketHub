<?php

use App\Services\AdmissionService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_starts_with(config('admission.namespace'), 'test-')) {
    throw new RuntimeException('Test namespace required');
}
[$script,$member,$barrier,$index,$mode,$token] = $argv;
file_put_contents($barrier.'.ready.'.$index, 'ready');
$deadline = microtime(true) + 20;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Barrier timeout');
    } usleep(10000);
}
$a = app(AdmissionService::class);
if ($mode === 'claim') {
    try {
        $a->claim($token, (int) $member, 1);
        echo json_encode(['state' => 'claimed']);
    } catch (HttpException $e) {
        echo json_encode(['state' => 'denied', 'status' => $e->getStatusCode()]);
    }
} else {
    $state = $a->join((int) $member, 1);
    unset($state['queue_token']);
    echo json_encode($state);
}
