<?php

namespace App\Http\Controllers\Api\V1\Integration;

use App\Http\Controllers\Controller;
use App\Services\Integration\MasarStatusEnvelope;
use Illuminate\Http\JsonResponse;

/**
 * Reachability and contract version, unauthenticated (CONTRACT §3.21.2).
 *
 * The same courtesy Masar's own integration surface offers the delivery company:
 * a way to prove the channel is up and agreeing about its version before any
 * credential is involved. It reveals nothing about orders and takes no input.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'service' => 'mini-delivery-integration',
            'contract_version' => config('integration.contract_version'),
            'accepts' => [MasarStatusEnvelope::EVENT_TYPE],
        ]);
    }
}
