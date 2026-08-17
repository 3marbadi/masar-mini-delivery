<?php

namespace App\Enums;

enum RouteDecision: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
