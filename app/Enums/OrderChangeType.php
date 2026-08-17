<?php

namespace App\Enums;

enum OrderChangeType: string
{
    case Location = 'location';
    case DeliveryStatus = 'delivery_status';
    case Readiness = 'readiness';
    case Data = 'data';
}
