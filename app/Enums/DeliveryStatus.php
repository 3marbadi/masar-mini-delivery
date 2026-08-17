<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case WithRepresentative = 'with_rep';
    case Delivered = 'delivered';
    case Postponed = 'postponed';
    case Returned = 'returned';
}
