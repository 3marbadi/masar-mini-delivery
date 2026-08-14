<?php

namespace App\Services;

use App\Enums\IntegrationEventType;
use App\Enums\IntegrationOutboxStatus;
use App\Enums\IntegrationSendResult;
use App\Models\IntegrationOutbox;
use App\Models\OrderIntegrationState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class IntegrationOutboxSender
{
    private const TERMINAL_SUCCESS_STATUSES = [
        'processed',
        'already_processed',
        'ignored_stale',
    ];

    private const RETRYABLE_HTTP_STATUSES = [429, 500, 503];

    /** @return Collection<int, IntegrationOutbox> */
    public function eligibleBatch(): Collection
    {
        return IntegrationOutbox::query()
            ->where('status', IntegrationOutboxStatus::Pending)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('integration_outbox as older')
                    ->whereColumn('older.delivery_order_id', 'integration_outbox.delivery_order_id')
                    ->whereColumn('older.order_version', '<', 'integration_outbox.order_version')
                    ->whereIn('older.status', [
                        IntegrationOutboxStatus::Pending->value,
                        IntegrationOutboxStatus::Failed->value,
                    ]);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit((int) config('services.masar.batch_limit', 50))
            ->get();
    }

    public function send(IntegrationOutbox $event): IntegrationSendResult
    {
        $baseUrl = rtrim((string) config('services.masar.base_url'), '/');
        $token = (string) config('services.masar.token');

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException('Masar integration URL and token must be configured.');
        }

        $lockName = 'masar_'.md5((string) DB::getDatabaseName()).'_'.$event->getKey();

        if (! $this->acquireLock($lockName)) {
            return IntegrationSendResult::Skipped;
        }

        try {
            $event->refresh();

            if (
                $event->status !== IntegrationOutboxStatus::Pending
                || $this->hasUnresolvedOlderVersion($event)
            ) {
                return IntegrationSendResult::Skipped;
            }

            $event->forceFill([
                'attempts' => $event->attempts + 1,
                'last_attempt_at' => now(),
            ])->save();

            try {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->asJson()
                    ->timeout((int) config('services.masar.timeout', 10))
                    ->post($baseUrl.(string) config('services.masar.endpoint_path'), $event->payload);
            } catch (ConnectionException $exception) {
                $this->markPending($event, $this->safeConnectionError($exception));

                return IntegrationSendResult::PendingRetry;
            }

            return $this->handleResponse($event, $response);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    private function handleResponse(IntegrationOutbox $event, Response $response): IntegrationSendResult
    {
        if ($response->status() === 200) {
            $data = $response->json();

            if (
                is_array($data)
                && ($data['success'] ?? null) === true
                && ($data['event_id'] ?? null) === $event->event_id
                && in_array($data['status'] ?? null, self::TERMINAL_SUCCESS_STATUSES, true)
            ) {
                $this->markSent($event);

                return IntegrationSendResult::Sent;
            }

            $this->markFailed($event, $this->invalidResponseReason($event, $data));

            return IntegrationSendResult::Failed;
        }

        $error = 'HTTP '.$response->status().' '.$this->httpReason($response->status());

        if (in_array($response->status(), self::RETRYABLE_HTTP_STATUSES, true)) {
            $this->markPending($event, $error);

            return IntegrationSendResult::PendingRetry;
        }

        $this->markFailed($event, $error);

        return IntegrationSendResult::Failed;
    }

    private function markSent(IntegrationOutbox $event): void
    {
        DB::transaction(function () use ($event): void {
            $lockedEvent = IntegrationOutbox::query()->lockForUpdate()->findOrFail($event->getKey());
            $sentAt = now();

            $lockedEvent->forceFill([
                'status' => IntegrationOutboxStatus::Sent,
                'sent_at' => $sentAt,
                'last_error' => null,
            ])->save();

            if ($lockedEvent->event_type === IntegrationEventType::OrderAssigned) {
                OrderIntegrationState::query()
                    ->where('delivery_order_id', $lockedEvent->delivery_order_id)
                    ->lockForUpdate()
                    ->firstOrFail()
                    ->forceFill(['assigned_transmitted_at' => $sentAt])
                    ->save();
            }
        });
    }

    private function markPending(IntegrationOutbox $event, string $error): void
    {
        $event->forceFill([
            'status' => IntegrationOutboxStatus::Pending,
            'sent_at' => null,
            'last_error' => $error,
        ])->save();
    }

    private function markFailed(IntegrationOutbox $event, string $error): void
    {
        $event->forceFill([
            'status' => IntegrationOutboxStatus::Failed,
            'sent_at' => null,
            'last_error' => $error,
        ])->save();
    }

    private function hasUnresolvedOlderVersion(IntegrationOutbox $event): bool
    {
        return IntegrationOutbox::query()
            ->where('delivery_order_id', $event->delivery_order_id)
            ->where('order_version', '<', $event->order_version)
            ->whereIn('status', [
                IntegrationOutboxStatus::Pending,
                IntegrationOutboxStatus::Failed,
            ])
            ->exists();
    }

    private function acquireLock(string $name): bool
    {
        $result = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name]);

        return (int) ($result->acquired ?? 0) === 1;
    }

    private function releaseLock(string $name): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
    }

    private function invalidResponseReason(IntegrationOutbox $event, mixed $data): string
    {
        if (! is_array($data)) {
            return 'Invalid integration response: malformed JSON';
        }

        if (($data['event_id'] ?? null) !== $event->event_id) {
            return 'Invalid integration response: event_id mismatch';
        }

        if (($data['success'] ?? null) !== true) {
            return 'Invalid integration response: success is not true';
        }

        return 'Invalid integration response: unsupported status';
    }

    private function safeConnectionError(Throwable $exception): string
    {
        return str_contains(strtolower($exception->getMessage()), 'timed out')
            ? 'Connection timeout'
            : 'Connection failed';
    }

    private function httpReason(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            404 => 'Not Found',
            409 => 'Conflict',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => 'Unexpected Response',
        };
    }
}
