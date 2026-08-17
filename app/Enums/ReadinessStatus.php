<?php

namespace App\Enums;

enum ReadinessStatus: string
{
    case NotContacted = 'not_contacted';
    case Confirmed = 'confirmed';
    case NoAnswer = 'no_answer';
    case NotReady = 'not_ready';
}
