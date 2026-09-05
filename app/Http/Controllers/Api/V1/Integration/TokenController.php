<?php

namespace App\Http\Controllers\Api\V1\Integration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\IssueMasarTokenRequest;
use App\Models\MasarIntegrationClient;
use App\Models\MasarIntegrationToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues Masar a bearer token (CONTRACT §3.21.2).
 *
 * The mirror of the endpoint the delivery company already calls on Masar, with
 * the parties exchanged. Credentials in, short-lived token out.
 *
 * An unknown client id and a wrong secret answer identically, and `Hash::check`
 * runs in both cases: answering "no such client" faster than "wrong secret"
 * turns this endpoint into a way to enumerate client ids.
 *
 * A disabled client is told apart from a bad credential, because §3.21.7 gives
 * the two different instructions — one is renewed and retried, the other needs a
 * person.
 */
class TokenController extends Controller
{
    public function __invoke(IssueMasarTokenRequest $request): JsonResponse
    {
        $client = MasarIntegrationClient::query()
            ->where('client_id', $request->validated('client_id'))
            ->first();

        // A constant stand-in when there is no client, so the comparison costs
        // the same either way.
        $hash = $client->client_secret_hash ?? Hash::make('no-such-client');

        if (! Hash::check($request->validated('client_secret'), $hash) || $client === null) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'AUTH_FAILED', 'message' => 'Invalid integration credentials.'],
                'request_id' => $request->attributes->get('request_id'),
            ], 401);
        }

        if (! $client->isActive()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FORBIDDEN_CLIENT', 'message' => 'The integration client is not allowed to access this service.'],
                'request_id' => $request->attributes->get('request_id'),
            ], 403);
        }

        $ttl = max(1, (int) config('integration.token_ttl_minutes', 60));
        $plain = Str::random(64);

        MasarIntegrationToken::create([
            'masar_integration_client_id' => $client->id,
            // Only the digest is kept. The plaintext leaves in the response
            // below and exists nowhere else in this system.
            'token_hash' => hash('sha256', $plain),
            'expires_at' => Carbon::now()->addMinutes($ttl),
        ]);

        return response()->json([
            'success' => true,
            'access_token' => $plain,
            'token_type' => 'Bearer',
            'expires_in' => $ttl * 60,
        ]);
    }
}
