<?php

use App\Models\Order;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\BookingService;
use App\Services\OutboxPublisher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('orders:expire {--limit=1000}', function (BookingService $service) {
    $count = 0;
    $limit = max(1, min(10000, (int) $this->option('limit')));
    Order::where('status', 'pending')->where('expires_at', '<=', now())->chunkById(200, function ($orders) use ($service, &$count, $limit) {
        foreach ($orders as $o) {
            if ($count >= $limit) {
                return false;
            }
            $service->expire($o);
            $count++;
        }
    });
    $this->info("已檢查 {$count} 筆逾期訂單。");
})->purpose('釋放逾期訂單保留名額');
Schedule::command('orders:expire --limit=1000')->everyTenSeconds()->withoutOverlapping(2)->onOneServer();
Artisan::command('app:make-admin', function () {
    $email = mb_strtolower($this->ask('管理員 Email'));
    $name = $this->ask('名稱', '活動管理員');
    $password = $this->secret('密碼（至少 12 字元）');
    $data = Validator::make(compact('email', 'name', 'password'), ['email' => 'required|email|unique:users', 'name' => 'required|max:100', 'password' => 'required|min:12'])->validate();
    $user = new User($data);
    $user->is_admin = true;
    $user->email_verified_at = now();
    $user->save();
    $this->info('管理員建立完成。');
})->purpose('互動建立管理員，不提供預設密碼');

Artisan::command('outbox:dispatch {--limit=100}', function (OutboxPublisher $publisher): void {
    $this->info('已提交 '.$publisher->publish((int) $this->option('limit')).' 筆通知工作。');
});
Schedule::command('outbox:dispatch --limit=100')->everyTenSeconds()->withoutOverlapping(2)->onOneServer();
Artisan::command('admission:metrics', function (AdmissionService $admission): void {
    $this->line(json_encode($admission->metrics(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
});
