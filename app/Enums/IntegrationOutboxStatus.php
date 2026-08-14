<?php

namespace App\Enums;

enum IntegrationOutboxStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
