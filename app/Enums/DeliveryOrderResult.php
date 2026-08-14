<?php

namespace App\Enums;

enum DeliveryOrderResult: string
{
    case Delivered = 'delivered';
    case NotDelivered = 'not_delivered';
}
