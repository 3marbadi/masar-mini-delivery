<?php

namespace App\Console\Commands;

use App\Enums\IntegrationOutboxStatus;
use App\Models\IntegrationOutbox;
use Illuminate\Console\Command;

class RetryIntegrationEvent extends Command
{
    protected $signature = 'integration:retry {event_id}';

    protected $description = 'Reset a failed Masar integration event for manual retry';

    public function handle(): int
    {
        $event = IntegrationOutbox::query()
            ->where('event_id', (string) $this->argument('event_id'))
            ->first();

        if (! $event) {
            $this->error('Integration event was not found.');

            return self::FAILURE;
        }

        if ($event->status !== IntegrationOutboxStatus::Failed) {
            $this->error('Only failed integration events can be retried.');

            return self::FAILURE;
        }

        $event->forceFill([
            'status' => IntegrationOutboxStatus::Pending,
            'attempts' => 0,
            'last_attempt_at' => null,
            'next_attempt_at' => now(),
            'sent_at' => null,
            'last_error' => null,
        ])->save();

        $this->info("Integration event {$event->event_id} is pending retry.");

        return self::SUCCESS;
    }
}
