<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdmissionService
{
    private static ?string $script = null;

    public function keys(): array
    {
        $prefix = '{'.config('admission.namespace').':admission}:';

        return array_map(fn (string $s): string => $prefix.$s, ['waiting', 'deadlines', 'active', 'nonces', 'types', 'sequence', 'bucket', 'claims', 'metrics']);
    }

    private function run(string $operation, string $user = '', string $type = '', string $nonce = '', string $claim = ''): array
    {
        self::$script ??= file_get_contents(resource_path('lua/admission.lua'));
        $args = [$operation, $user, $type, $nonce, $claim,
            max(1, config('admission.slots')), max(1, config('admission.rate')), max(1, config('admission.burst')),
            max(1, config('admission.permit_seconds')) * 1000, max(1, config('admission.processing_seconds')) * 1000,
            max(1, config('admission.heartbeat_seconds')) * 1000, max(1, config('admission.max_waiters'))];
        try {
            $raw = Redis::connection('admission')->eval(self::$script, count($this->keys()), ...$this->keys(), ...$args);

            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            report($error);
            throw new HttpException(503, '候位服務暫時無法使用，請稍後再試。', $error, ['Retry-After' => '5']);
        }
    }

    private function credential(string $token): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $e) {
            abort(403, '候位憑證無效，請重新候位。');
        }
        abort_unless(is_array($data) && isset($data['user'],$data['type'],$data['nonce'],$data['expires']) && is_int($data['expires']) && $data['expires'] > time(), 403, '候位憑證已過期。');

        return $data;
    }

    public function join(int $userId, int $typeId): array
    {
        $nonce = Str::random(48);
        $state = $this->run('join', (string) $userId, (string) $typeId, $nonce);
        if (isset($state['nonce'])) {
            $state['queue_token'] = Crypt::encryptString(json_encode(['user' => $userId, 'type' => $typeId, 'nonce' => $state['nonce'], 'expires' => time() + config('admission.token_seconds')], JSON_THROW_ON_ERROR));
            unset($state['nonce']);
        }

        return $state;
    }

    public function status(string $token): array
    {
        $c = $this->credential($token);
        $state = $this->run('status', (string) $c['user'], (string) $c['type'], $c['nonce']);
        unset($state['nonce']);

        return $state;
    }

    public function leave(string $token): array
    {
        $c = $this->credential($token);

        return $this->run('leave', (string) $c['user'], (string) $c['type'], $c['nonce']);
    }

    public function claim(string $token, int $userId, int $typeId): array
    {
        $c = $this->credential($token);
        abort_unless($c['user'] === $userId && $c['type'] === $typeId, 403, '候位憑證不屬於此會員或票種。');
        $claim = (string) Str::uuid();
        $state = $this->run('claim', (string) $userId, (string) $typeId, $c['nonce'], $claim);
        if ($state['state'] !== 'claimed') {
            throw new HttpException(429, '尚未放行、資格已過期或有訂單正在處理。請回到候位頁。', null, ['Retry-After' => '5']);
        }

        return ['credential' => $c, 'claim' => $claim];
    }

    public function release(array $lease): void
    {
        $c = $lease['credential'];
        $this->run('release', (string) $c['user'], (string) $c['type'], $c['nonce'], $lease['claim']);
    }

    public function metrics(): array
    {
        return $this->run('metrics');
    }
}
