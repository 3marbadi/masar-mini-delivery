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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class IntegrationOutboxSender
{
    private const TERMINAL_SUCCESS_STATUSES = ['processed', 'already_processed', 'ignored_stale'];

    private const RETRYABLE_HTTP_STATUSES = [429, 500, 503];

    public function __construct(private MasarAccessTokenProvider $tokens) {}

    /** @return Collection<int, IntegrationOutbox> */
    public function eligibleBatch(): Collection
    {
        return IntegrationOutbox::query()
            ->where('status', IntegrationOutboxStatus::Pending)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('integration_outbox as older')
                    ->whereColumn('older.delivery_order_id', 'integration_outbox.delivery_order_id')
                    ->whereColumn('older.order_version', '<', 'integration_outbox.order_version')
                    ->whereIn('older.status', [IntegrationOutboxStatus::Pending->value, IntegrationOutboxStatus::Failed->value]);
            })->orderBy('created_at')->orderBy('id')->limit((int) config('services.masar.batch_limit', 50))->get();
    }

    public function send(IntegrationOutbox $event): IntegrationSendResult
    {
        if (rtrim((string) config('services.masar.base_url'), '/') === '') {
            throw new RuntimeException('Masar integration URL must be configured.');
        }
        $lockName = 'masar_'.md5((string) DB::getDatabaseName()).'_'.$event->getKey();
        if (! $this->acquireLock($lockName)) {
            return IntegrationSendResult::Skipped;
        }

        try {
            $event->refresh();
            if ($event->status !== IntegrationOutboxStatus::Pending || $event->next_attempt_at?->isFuture() || $this->hasUnresolvedOlderVersion($event)) {
                return IntegrationSendResult::Skipped;
            }
            try {
                $token = $this->tokens->token();
            } catch (RuntimeException $e) {
                $this->scheduleWithoutAttempt($event, $e->getMessage());

                return IntegrationSendResult::PendingRetry;
            }

            $response = $this->postEvent($event, $token);
            if ($response instanceof IntegrationSendResult) {
                return $response;
            }
            if ($response->status() === 401) {
                $this->tokens->invalidate();
                try {
                    $token = $this->tokens->token();
                } catch (RuntimeException $e) {
                    $this->scheduleWithoutAttempt($event, $e->getMessage());

                    return IntegrationSendResult::PendingRetry;
                }
                $response = $this->postEvent($event, $token);
                if ($response instanceof IntegrationSendResult) {
                    return $response;
                }
                if ($response->status() === 401) {
                    return $this->markFailedResult($event, $this->contractError($response));
                }
            }

            return $this->handleResponse($event, $response);
        } finally {
            $this->releaseLock($lockName);
        }
    }

    private function postEvent(IntegrationOutbox $event, string $token): Response|IntegrationSendResult
    {
        $event->forceFill(['attempts' => $event->attempts + 1, 'last_attempt_at' => now(), 'next_attempt_at' => null])->save();
        try {
            return Http::withToken($token)->acceptJson()->asJson()->timeout((int) config('services.masar.timeout', 10))
                ->post(rtrim((string) config('services.masar.base_url'), '/').(string) config('services.masar.endpoint_path'), $event->payload);
        } catch (ConnectionException $e) {
            return $this->retryOrFail($event, $this->safeConnectionError($e));
        }
    }

    private function handleResponse(IntegrationOutbox $event, Response $response): IntegrationSendResult
    {
        if ($response->status() === 200) {
            $data = $response->json();
            if (is_array($data) && ($data['success'] ?? null) === true && ($data['event_id'] ?? null) === $event->event_id && in_array($data['status'] ?? null, self::TERMINAL_SUCCESS_STATUSES, true)) {
                $this->markSent($event);

                return IntegrationSendResult::Sent;
            }

            return $this->markFailedResult($event, $this->invalidResponseReason($event, $data));
        }
        if (in_array($response->status(), self::RETRYABLE_HTTP_STATUSES, true)) {
            return $this->retryOrFail($event, $this->contractError($response), $response->status() === 429 ? $this->retryAfter($response) : null);
        }

        return $this->markFailedResult($event, $this->contractError($response));
    }

    private function retryOrFail(IntegrationOutbox $event, string $error, ?Carbon $retryAt = null): IntegrationSendResult
    {
        if ($event->attempts >= (int) config('services.masar.max_attempts', 5)) {
            return $this->markFailedResult($event, $error);
        }
        $event->forceFill(['status' => IntegrationOutboxStatus::Pending, 'sent_at' => null, 'last_error' => $error, 'next_attempt_at' => $retryAt ?? now()->addSeconds($this->backoffSeconds($event->attempts))])->save();

        return IntegrationSendResult::PendingRetry;
    }

    private function scheduleWithoutAttempt(IntegrationOutbox $event, string $error): void
    {
        $event->forceFill(['status' => IntegrationOutboxStatus::Pending, 'last_error' => $error, 'next_attempt_at' => now()->addMinute()])->save();
    }

    private function markFailedResult(IntegrationOutbox $event, string $error): IntegrationSendResult
    {
        $event->forceFill(['status' => IntegrationOutboxStatus::Failed, 'sent_at' => null, 'next_attempt_at' => null, 'last_error' => $error])->save();

        return IntegrationSendResult::Failed;
    }

    private function markSent(IntegrationOutbox $event): void
    {
        DB::transaction(function () use ($event): void {
            $locked = IntegrationOutbox::query()->lockForUpdate()->findOrFail($event->getKey());
            $sentAt = now();
            $locked->forceFill(['status' => IntegrationOutboxStatus::Sent, 'sent_at' => $sentAt, 'next_attempt_at' => null, 'last_error' => null])->save();
            if ($locked->event_type === IntegrationEventType::OrderAssigned) {
                OrderIntegrationState::query()->where('delivery_order_id', $locked->delivery_order_id)->lockForUpdate()->firstOrFail()->forceFill(['assigned_transmitted_at' => $sentAt])->save();
            }
        });
    }

    private function hasUnresolvedOlderVersion(IntegrationOutbox $event): bool
    {
        return IntegrationOutbox::query()->where('delivery_order_id', $event->delivery_order_id)->where('order_version', '<', $event->order_version)->whereIn('status', [IntegrationOutboxStatus::Pending, IntegrationOutboxStatus::Failed])->exists();
    }

    private function retryAfter(Response $response): ?Carbon
    {
        $value = $response->header('Retry-After');
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return now()->addSeconds(min(3600, max(1, (int) $value)));
        }
        try {
            $date = Carbon::parse($value);

            return $date->isAfter(now()) && now()->diffInSeconds($date) <= 3600 ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function backoffSeconds(int $attempt): int
    {
        return match ($attempt) {
            1 => 60, 2 => 300, 3 => 900, default => 3600
        };
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

    private function safeConnectionError(Throwable $e): string
    {
        return str_contains(strtolower($e->getMessage()), 'timed out') ? 'Connection timeout' : 'Connection failed';
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

    private function contractError(Response $response): string
    {
        $data = $response->json();
        $code = is_array($data) ? ($data['error']['code'] ?? null) : null;
        $message = is_array($data) ? ($data['error']['message'] ?? null) : null;

        return is_string($code) && is_string($message)
            ? $this->redactSecrets($code.': '.$message)
            : 'HTTP '.$response->status().' '.$response->reason();
    }

    private function redactSecrets(string $message): string
    {
        $secret = (string) config('services.masar.client_secret');

        if ($secret !== '') {
            $message = str_replace($secret, '[redacted]', $message);
        }

        return preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [redacted]', $message) ?? 'Integration request failed';
    }
}
