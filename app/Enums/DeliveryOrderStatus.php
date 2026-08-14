<?php

namespace App\Enums;

enum DeliveryOrderStatus: string
{
    case NewOrder = 'new';
    case Assigned = 'assigned';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
