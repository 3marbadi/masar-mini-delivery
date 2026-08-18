<?php

namespace Tests\Feature;

use App\Services\MasarAccessTokenProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MasarAccessTokenProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set([
            'services.masar.base_url' => 'https://masar.test',
            'services.masar.client_id' => 'mini-delivery',
            'services.masar.client_secret' => 'client-secret',
            'services.masar.token_path' => '/api/v1/integration/auth/token',
            'services.masar.token_safety_seconds' => 60,
        ]);
    }

    public function test_it_fetches_and_caches_a_valid_bearer_token(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'access_token' => 'access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ])]);

        $provider = app(MasarAccessTokenProvider::class);

        $this->assertSame('access-token', $provider->token());
        $this->assertSame('access-token', $provider->token());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://masar.test/api/v1/integration/auth/token'
            && $request['client_id'] === 'mini-delivery'
            && $request['client_secret'] === 'client-secret');
    }

    public function test_invalidation_forces_a_new_token_request(): void
    {
        Http::fakeSequence()
            ->push(['success' => true, 'access_token' => 'first', 'token_type' => 'Bearer', 'expires_in' => 3600])
            ->push(['success' => true, 'access_token' => 'second', 'token_type' => 'Bearer', 'expires_in' => 3600]);

        $provider = app(MasarAccessTokenProvider::class);
        $this->assertSame('first', $provider->token());
        $provider->invalidate();
        $this->assertSame('second', $provider->token());
        Http::assertSentCount(2);
    }
}
