<?php

namespace App\Console\Commands;

use App\Enums\IntegrationSendResult;
use App\Services\IntegrationOutboxSender;
use Illuminate\Console\Command;
use Throwable;

class SendIntegrationOutbox extends Command
{
    protected $signature = 'integration:send';

    protected $description = 'Send the next eligible Masar integration outbox events';

    public function handle(IntegrationOutboxSender $sender): int
    {
        $counts = [
            IntegrationSendResult::Sent->value => 0,
            IntegrationSendResult::PendingRetry->value => 0,
            IntegrationSendResult::Failed->value => 0,
            IntegrationSendResult::Skipped->value => 0,
        ];

        $events = $sender->eligibleBatch();

        foreach ($events as $event) {
            try {
                $result = $sender->send($event);
                $counts[$result->value]++;
            } catch (Throwable) {
                $counts[IntegrationSendResult::Failed->value]++;
            }
        }

        $this->line(sprintf(
            'Processed: %d | Sent: %d | Pending retry: %d | Failed: %d | Blocked: %d',
            $events->count(),
            $counts[IntegrationSendResult::Sent->value],
            $counts[IntegrationSendResult::PendingRetry->value],
            $counts[IntegrationSendResult::Failed->value],
            $counts[IntegrationSendResult::Skipped->value],
        ));

        return self::SUCCESS;
    }
}
