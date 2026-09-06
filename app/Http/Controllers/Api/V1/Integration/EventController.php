<?php

namespace App\Http\Controllers\Api\V1\Integration;

use App\Exceptions\MasarIntegrationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integration\ReceiveMasarEventRequest;
use App\Models\MasarIntegrationClient;
use App\Services\Integration\MasarDataEnvelope;
use App\Services\Integration\MasarDataEventProcessor;
use App\Services\Integration\MasarStatusEventProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one door Masar knocks on (CONTRACT §3.21, §13.8).
 *
 * Thin by intent. The idempotency decision, the order lookup and the write all
 * belong to the processor, because a replay must answer exactly as the original
 * did and that answer cannot be reassembled out here.
 *
 * Two channels arrive through this one door and are handed to two processors.
 * They share an idempotency table and share nothing else: separate version
 * sequences (§13.8.2), separate writers, separate refusal vocabularies. Deciding
 * between them here, on the event type the request has already validated, is the
 * whole of the routing — a processor that had to ask which kind of event it was
 * holding would be two processors with a branch in the middle.
 *
 * A refusal that reached a judgement carries its contract code; anything
 * unforeseen is logged and answered `SERVER_ERROR`, which §3.21.7 classifies as
 * retryable — so an internal fault here becomes a retry rather than an
 * announcement silently lost.
 */
class EventController extends Controller
{
    public function __invoke(
        ReceiveMasarEventRequest $request,
        MasarStatusEventProcessor $status,
        MasarDataEventProcessor $data,
    ): JsonResponse {
        $client = $request->attributes->get('masar_integration_client');
        abort_unless($client instanceof MasarIntegrationClient, 401);

        $requestId = (string) $request->attributes->get('request_id');

        $payload = $request->validated();

        $processor = $payload['event_type'] === MasarDataEnvelope::EVENT_TYPE ? $data : $status;

        try {
            $result = $processor->process($client, $payload, $requestId);

            return response()->json($result['body'], $result['status']);
        } catch (MasarIntegrationException $exception) {
            return response()->json([
                'success' => false,
                'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
                'request_id' => $requestId,
            ], $exception->httpStatus);
        } catch (Throwable $exception) {
            Log::error('Masar integration event processing failed.', [
                'request_id' => $requestId,
                'exception' => $exception,
            ]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'SERVER_ERROR', 'message' => 'The integration event could not be processed.'],
                'request_id' => $requestId,
            ], 500);
        }
    }
}
