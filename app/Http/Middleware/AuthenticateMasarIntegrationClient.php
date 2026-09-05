<?php

namespace App\Http\Middleware;

use App\Models\MasarIntegrationToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proves the caller is Masar, and is still allowed in (CONTRACT §3.21.2).
 *
 * Written rather than delegated to Sanctum, which is not installed in this
 * application. Pulling a package in for one server-to-server endpoint would add
 * a dependency, a migration and a guard configuration to gain a token lookup
 * that is four lines — and §9 of the implementation brief asks explicitly for no
 * new dependency where none is needed.
 *
 * Two failures, kept apart because §3.21.7 gives them different meanings and
 * different instructions to the sender. A missing, unknown or expired token is
 * `401 AUTH_FAILED`: Masar renews and retries. A valid token belonging to a
 * disabled client is `403 FORBIDDEN_CLIENT`: renewal changes nothing, and the
 * contract tells Masar not to retry automatically.
 *
 * The lookup is by hash, so the stored value is never a usable credential. The
 * comparison is a unique-index lookup on a digest rather than a scan with
 * hash_equals — there is nothing secret in the *stored* side to leak by timing,
 * and the digest is of a 40-character random string.
 */
class AuthenticateMasarIntegrationClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if (! is_string($plain) || $plain === '') {
            return $this->unauthenticated($request);
        }

        $token = MasarIntegrationToken::query()
            ->with('client')
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        if ($token === null || $token->expires_at->isPast()) {
            return $this->unauthenticated($request);
        }

        $client = $token->client;

        if ($client === null || ! $client->isActive()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FORBIDDEN_CLIENT', 'message' => 'The integration client is not allowed to access this service.'],
                'request_id' => $request->attributes->get('request_id'),
            ], 403);
        }

        // Operational visibility, not a security control: it answers "is this
        // channel actually being used" without a log to correlate. Written
        // outside any transaction the request may open, so it cannot roll back
        // with a rejected event.
        $token->forceFill(['last_used_at' => Carbon::now()])->save();

        $request->attributes->set('masar_integration_client', $client);

        return $next($request);
    }

    private function unauthenticated(Request $request): Response
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => 'AUTH_FAILED', 'message' => 'Invalid or expired bearer token.'],
            'request_id' => $request->attributes->get('request_id'),
        ], 401);
    }
}
