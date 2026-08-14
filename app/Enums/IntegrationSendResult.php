<?php

namespace App\Enums;

enum IntegrationSendResult: string
{
    case Sent = 'sent';
    case PendingRetry = 'pending_retry';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
