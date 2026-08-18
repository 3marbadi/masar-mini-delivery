<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MasarAccessTokenProvider
{
    public function token(): string
    {
        $key = $this->cacheKey();
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $baseUrl = rtrim((string) config('services.masar.base_url'), '/');
        $clientId = (string) config('services.masar.client_id');
        $secret = (string) config('services.masar.client_secret');

        if ($baseUrl === '' || $clientId === '' || $secret === '') {
            throw new RuntimeException('Masar integration credentials are not configured.');
        }

        try {
            $response = Http::acceptJson()->asJson()
                ->timeout((int) config('services.masar.timeout', 10))
                ->post($baseUrl.(string) config('services.masar.token_path'), [
                    'client_id' => $clientId,
                    'client_secret' => $secret,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Masar token service connection failed.');
        }

        $data = $response->json();

        if (! $response->successful() || ! is_array($data)
            || ($data['success'] ?? null) !== true
            || ! is_string($data['access_token'] ?? null) || $data['access_token'] === ''
            || ($data['token_type'] ?? null) !== 'Bearer'
            || ! is_int($data['expires_in'] ?? null) || $data['expires_in'] <= 0) {
            throw new RuntimeException('Masar token service returned an invalid response.');
        }

        $safety = max(0, (int) config('services.masar.token_safety_seconds', 60));
        $ttl = max(1, $data['expires_in'] - $safety);
        Cache::put($key, $data['access_token'], $ttl);

        return $data['access_token'];
    }

    public function invalidate(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'masar:integration:token:'.hash('sha256', rtrim((string) config('services.masar.base_url'), '/').'|'.(string) config('services.masar.client_id'));
    }
}
