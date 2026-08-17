<?php

namespace App\Enums;

enum RouteStatus: string
{
    case Proposed = 'proposed';
    case Active = 'active';
    case Rejected = 'rejected';
}
